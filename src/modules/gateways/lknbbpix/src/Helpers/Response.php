<?php

namespace Lkn\BBPix\Helpers;

abstract class Response
{
    private static function response(bool $success, array $data = []): array
    {
        $response = ['success' => $success];

        if (count($data) > 0) {
            $response['data'] = $data;
        }

        return $response;
    }

    final public static function api(bool $success, array $data = []): void
    {
        echo json_encode(
            self::response($success, $data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    final public static function return(bool $success, array $data = []): array|string
    {
        return self::response($success, $data);
    }
}
