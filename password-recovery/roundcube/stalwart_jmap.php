<?php

/**
 * Roundcube password driver for Stalwart's self-service JMAP API.
 *
 * The request is authenticated as the signed-in mailbox owner. No global
 * administrator token is required or stored by Roundcube.
 */
class rcube_stalwart_jmap_password
{
    public function save($currentPassword, $newPassword, $username)
    {
        $config = rcmail::get_instance()->config;
        $url = rtrim($config->get('password_stalwart_jmap_url', 'http://stalwart:8080/jmap/'), '/') . '/';
        $client = password::get_http_client();

        try {
            $response = $client->request('POST', $url, [
                'auth' => [$username, $currentPassword],
                'headers' => ['Accept' => 'application/json'],
                'json' => [
                    'using' => ['urn:ietf:params:jmap:core', 'urn:stalwart:jmap'],
                    'methodCalls' => [[
                        'x:AccountPassword/set',
                        [
                            'update' => [
                                'singleton' => [
                                    'currentSecret' => $currentPassword,
                                    'secret' => $newPassword,
                                ],
                            ],
                        ],
                        'change-password',
                    ]],
                ],
                'timeout' => 15,
            ]);

            if ($response->getStatusCode() !== 200) {
                return PASSWORD_ERROR;
            }

            $body = json_decode((string) $response->getBody(), true);
            $result = $body['methodResponses'][0] ?? null;

            if (!is_array($result) || ($result[0] ?? '') !== 'x:AccountPassword/set') {
                return PASSWORD_ERROR;
            }

            $data = $result[1] ?? [];
            if (!empty($data['notUpdated']) || !array_key_exists('singleton', $data['updated'] ?? [])) {
                return PASSWORD_ERROR;
            }

            return PASSWORD_SUCCESS;
        } catch (\Throwable $error) {
            rcube::write_log('errors', 'Stalwart password change failed for ' . $username . ': ' . $error->getMessage());
            return PASSWORD_CONNECT_ERROR;
        }
    }
}
