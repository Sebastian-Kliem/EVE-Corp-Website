<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class JwtService
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $appSecret
    ) {}

    /**
     * Create a signed JWT (JWS) for a user.
     */
    public function createToken(User $user, int $ttl = 3600): string
    {
        $header = json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]);

        $payload = json_encode([
            'sub' => $user->getUsername(),
            'roles' => $user->getRoles(),
            'exp' => time() + $ttl,
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload);

        $signature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, $this->appSecret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        return $base64UrlHeader . '.' . $base64UrlPayload . '.' . $base64UrlSignature;
    }

    /**
     * Parse and validate a token, returning the payload if valid.
     */
    public function parseAndValidate(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$base64UrlHeader, $base64UrlPayload, $base64UrlSignature] = $parts;

        $signature = $this->base64UrlDecode($base64UrlSignature);
        if ($signature === null) {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $base64UrlHeader . '.' . $base64UrlPayload, $this->appSecret, true);

        if (!hash_equals($expectedSignature, $signature)) {
            return null; // Signature is invalid
        }

        $payloadJson = $this->base64UrlDecode($base64UrlPayload);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload) || !isset($payload['exp']) || !isset($payload['sub'])) {
            return null;
        }

        if (time() > $payload['exp']) {
            return null; // Token has expired
        }

        return $payload;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $data), true);
        
        return $decoded !== false ? $decoded : null;
    }
}
