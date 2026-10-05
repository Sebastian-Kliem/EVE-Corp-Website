<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\JwtAuthenticator;
use App\Service\JwtService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

class JwtAuthenticatorTest extends TestCase
{
    private const APP_SECRET = 'test-app-secret';

    public function testValidTokenAuthenticatesUser(): void
    {
        $user = $this->_createUser();
        $token = (new JwtService(self::APP_SECRET))->createToken($user);

        $this->assertSame($user, $this->_authenticate($token, $user));
    }

    public function testTokenIssuedBeforeLogoutIsRejected(): void
    {
        $user = $this->_createUser();
        $token = $this->_createTokenIssuedAt($user, time() - 60);
        $user->invalidateApiTokens();

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->_authenticate($token, $user);
    }

    public function testTokenSignedWithRawAppSecretIsRejected(): void
    {
        $header = $this->_base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->_base64UrlEncode(json_encode(['sub' => 'pilot', 'roles' => [], 'iat' => time(), 'exp' => time() + 3600]));
        $signature = $this->_base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, self::APP_SECRET, true));

        $this->assertNull((new JwtService(self::APP_SECRET))->parseAndValidate($header . '.' . $payload . '.' . $signature));
    }

    private function _authenticate(string $token, User $user): User
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn($user);
        $authenticator = new JwtAuthenticator(new JwtService(self::APP_SECRET), $userRepository);

        $passport = $authenticator->authenticate(new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]));

        $authenticatedUser = $passport->getBadge(UserBadge::class)->getUser();
        $this->assertInstanceOf(User::class, $authenticatedUser);

        return $authenticatedUser;
    }

    // Signs like JwtService but with a chosen issue time
    private function _createTokenIssuedAt(User $user, int $issuedAt): string
    {
        $jwtService = new JwtService(self::APP_SECRET);
        $signingKey = (new \ReflectionProperty(JwtService::class, 'signingKey'))->getValue($jwtService);

        $header = $this->_base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->_base64UrlEncode(json_encode(['sub' => $user->getUsername(), 'roles' => [], 'iat' => $issuedAt, 'exp' => $issuedAt + 3600]));
        $signature = $this->_base64UrlEncode(hash_hmac('sha256', $header . '.' . $payload, $signingKey, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    private function _createUser(): User
    {
        return (new User())->setUsername('pilot');
    }

    private function _base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
