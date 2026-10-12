<?php

namespace Tests\Unit\AgentApi;

use App\Support\AgentApi\BearerRejection\BearerFingerprint;
use App\Support\AgentApi\BearerRejection\BearerRejectionClassifier;
use App\Support\AgentApi\BearerRejection\BearerRejectionReason;
use Lcobucci\JWT\Encoding\CannotDecodeContent;
use Lcobucci\JWT\Token\InvalidTokenStructure;
use Lcobucci\JWT\Token\UnsupportedHeaderFound;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\ConstraintViolation;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use League\OAuth2\Server\Exception\OAuthServerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BearerRejectionClassificationTest extends TestCase
{
    /** @return iterable<string, array{string, int, string, bool}> */
    public static function fingerprints(): iterable
    {
        yield 'refresh token' => ['def50200'.str_repeat('a1', 300), 608, 'def50200', false];
        yield 'jwt' => ['eyJ0eXAiOiJKV1Qi.eyJhdWQiOiJjIn0.c2lnbmF0dXJl', 45, 'eyJ0eXAi', true];
        yield 'seventeen characters shows eight' => ['Abcdefgh123456789', 17, 'Abcdefgh', false];
        yield 'sixteen characters shows seven' => ['Abcdefgh12345678', 16, 'Abcdefg', false];
        yield 'nine characters shows four' => ['Xv8Rk3Pq9', 9, 'Xv8R', false];
        yield 'eight characters shows three' => ['Xv8Rk3Pq', 8, 'Xv8', false];
        yield 'two characters show none' => ['Xv', 2, '', false];
        yield 'one character shows none' => ['X', 1, '', false];
        yield 'an empty segment is not a jwt' => ['aaaa..cccc-padding', 18, 'aaaa..cc', false];
        yield 'four segments are not a jwt' => ['aaaa.bbbb.cccc.dddd', 19, 'aaaa.bbb', false];
        yield 'two segments are not a jwt' => ['aaaa.bbbb-padding', 17, 'aaaa.bbb', false];
        yield 'a leading dot is not a jwt' => ['.bbbb.cccc-padding', 18, '.bbbb.cc', false];
        yield 'control characters and quotes are masked' => ["a\"b\x01c d\u{e9}-padding-padding", 25, 'a?b?c?d?', false];
        yield 'token alphabets are kept' => ['a-b_c~d+/=|tail-padding', 23, 'a-b_c~d+', false];
    }

    #[DataProvider('fingerprints')]
    public function test_a_fingerprint_shows_length_shape_and_less_than_half_of_the_token(string $token, int $length, string $prefix, bool $jwtShaped): void
    {
        $fingerprint = BearerFingerprint::of($token);

        $this->assertSame($length, $fingerprint->length);
        $this->assertSame($prefix, $fingerprint->prefix);
        $this->assertSame($jwtShaped, $fingerprint->jwtShaped);
        $this->assertLessThan($length, strlen($fingerprint->prefix) * 2, 'Less than half of the token is shown');
    }

    /** @return iterable<string, array{OAuthServerException, BearerRejectionReason}> */
    public static function validatorFailures(): iterable
    {
        $violated = static fn (ConstraintViolation ...$violations): OAuthServerException => OAuthServerException::accessDenied(
            'Access token could not be verified', null, RequiredConstraintsViolated::fromViolations(...$violations),
        );
        $expired = new ConstraintViolation('The token is expired', LooseValidAt::class);
        $signature = new ConstraintViolation('Token signature mismatch', SignedWith::class);

        yield 'no dots' => [OAuthServerException::accessDenied('The JWT string must have two dots', null, InvalidTokenStructure::missingOrNotEnoughSeparators()), BearerRejectionReason::InvalidStructure];
        yield 'undecodable' => [OAuthServerException::accessDenied('Error while decoding from Base64Url', null, CannotDecodeContent::invalidBase64String()), BearerRejectionReason::InvalidStructure];
        yield 'unsupported header' => [OAuthServerException::accessDenied('Encryption is not supported', null, UnsupportedHeaderFound::encryption()), BearerRejectionReason::InvalidStructure];
        yield 'expired' => [$violated($expired), BearerRejectionReason::Expired];
        yield 'bad signature' => [$violated($signature), BearerRejectionReason::BadSignature];
        yield 'a bad signature outranks expiry' => [$violated($expired, $signature), BearerRejectionReason::BadSignature];
        yield 'not yet valid' => [$violated(new ConstraintViolation('The token cannot be used yet', LooseValidAt::class)), BearerRejectionReason::InvalidToken];
        yield 'another constraint' => [$violated(new ConstraintViolation('The token was not issued by the given issuers', IssuedBy::class)), BearerRejectionReason::InvalidToken];
        yield 'revoked' => [OAuthServerException::accessDenied('Access token has been revoked'), BearerRejectionReason::Revoked];
        yield 'missing header' => [OAuthServerException::accessDenied('Missing "Authorization" header'), BearerRejectionReason::InvalidToken];
        yield 'another cause' => [OAuthServerException::accessDenied('Access token has been revoked', null, new RuntimeException('unrelated')), BearerRejectionReason::Revoked];
    }

    #[DataProvider('validatorFailures')]
    public function test_a_validator_failure_names_its_reason(OAuthServerException $failure, BearerRejectionReason $reason): void
    {
        $this->assertSame($reason, BearerRejectionClassifier::fromValidatorFailure($failure));
    }
}
