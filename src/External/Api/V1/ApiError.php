<?php

namespace KeepersTeam\Webtlo\External\Api\V1;

final class ApiError
{
    public function __construct(
        public readonly int $code,
        public readonly string $text
    ) {
    }

    public static function fromHttpCode(int $code): ApiError
    {
        return new ApiError(code: $code, text: 'Network error');
    }

    public static function fromLegacyError(?array $legacyError): ApiError
    {
        $error = $legacyError ?? [];
        return new ApiError(
            code: $error['code'] ?? -1,
            text: $error['text'] ?? 'Unknown API error'
        );
    }

    public static function invalidMime(): ApiError
    {
        return new ApiError(code: -2, text: 'Invalid mime');
    }

    public static function malformedJson(): ApiError
    {
        return new ApiError(code: -3, text: 'Malformed JSON');
    }
}