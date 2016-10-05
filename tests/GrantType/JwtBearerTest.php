<?php

namespace Frankkessler\Guzzle\Oauth2\Tests\GrantType;

use Frankkessler\Guzzle\Oauth2\GrantType\JwtBearer;
use Frankkessler\Guzzle\Oauth2\Oauth2Client;
use Frankkessler\Guzzle\Oauth2\Tests\GuzzleServer;
use Frankkessler\Guzzle\Oauth2\Tests\MockResponses;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Key;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class JwtBearerTest extends \Mockery\Adapter\Phpunit\MockeryTestCase
{
    public static function setUpBeforeClass()
    {
        parent::setUpBeforeClass();
        static::createKeys();
    }

    public static function createKeys()
    {
        $csrString = '';
        $privateKeyString = '';
        $certString = '';

        $config = [
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
        ];

        $dn = [
            'countryName'            => 'US',
            'stateOrProvinceName'    => 'New York',
            'localityName'           => 'New York',
            'organizationName'       => 'GuzzleOauth2Middleware',
            'organizationalUnitName' => 'PHP Unit Test',
            'commonName'             => 'GuzzleOauth2Middleware',
            'emailAddress'           => 'GuzzleOauth2Middleware@example.com',
        ];

        $privateKey = openssl_pkey_new($config);

        $csr = openssl_csr_new($dn, $privateKey);

        $sscert = openssl_csr_sign($csr, null, $privateKey, 365);

        openssl_csr_export($csr, $csrString);
        file_put_contents(__DIR__.'/../../build/csr.csr', $csrString);

        openssl_x509_export($sscert, $certString);
        file_put_contents(__DIR__.'/../../build/cert.crt', $certString);

        openssl_pkey_export($privateKey, $privateKeyString, 'testpassword');
        file_put_contents(__DIR__.'/../../build/cert.key', $privateKeyString);
    }

    public function testMissingConfigException()
    {
        $this->setExpectedException('\\InvalidArgumentException', 'Config is missing the following keys: client_id, jwt_private_key');
        new JwtBearer([]);
    }

    public function testJwtToken()
    {
        $signer = new Sha256();

        $privateKey = new Key(file_get_contents(__DIR__.'/../../build/cert.key'), 'testpassword');
        $publicKey = new Key(file_get_contents(__DIR__.'/../../build/cert.crt'));

        $token = (new Builder())->setIssuer('http://example.com') // Configures the issuer (iss claim)
        ->setAudience('http://example.org') // Configures the audience (aud claim)
        ->setId('4f1g23a12aa', true) // Configures the id (jti claim), replicating as a header item
        ->setIssuedAt(time()) // Configures the time that the token was issue (iat claim)
        ->setNotBefore(time() + 60) // Configures the time that the token can be used (nbf claim)
        ->setExpiration(time() + 3600) // Configures the expiration time of the token (nbf claim)
        ->set('uid', 1) // Configures a new claim, called "uid"
        ->sign($signer, $privateKey) // creates a signature using your private key
        ->getToken(); // Retrieves the generated token

        $tokenString = (string) $token;

        $token = (new Parser())->parse($tokenString);

        $this->assertTrue($token->verify($signer, $publicKey)); // true when the public key was generated by the private one =)
    }

    public function testValidRequestGetsToken()
    {
        $signer = new Sha256();

        $publicKey = new Key(file_get_contents(__DIR__.'/../../build/cert.crt'));

        GuzzleServer::flush();
        GuzzleServer::start();

        $token_url = GuzzleServer::$url.'oauth2/token';

        GuzzleServer::enqueue([
            new Response(200, [], MockResponses::returnRefreshTokenResponse()),
        ]);

        $client = new Oauth2Client([
            'auth'     => 'oauth2',
            'base_uri' => GuzzleServer::$url,
        ]);

        $grantType = new JwtBearer([
            'client_id'                  => 'testClient',
            'jwt_private_key'            => __DIR__.'/../../build/cert.key',
            'jwt_private_key_passphrase' => 'testpassword',
            'token_url'                  => $token_url,
        ]);

        $client->setGrantType($grantType);

        $token = $client->getAccessToken();
        $this->assertNotEmpty($token->getToken());
        $this->assertTrue($token->getExpires()->getTimestamp() > time());

        foreach (GuzzleServer::received() as $request) {
            /* @var Request $request */
                $this->assertContains('scope=&grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer&assertion=', (string) $request->getBody());

            $request_vars = [];
            parse_str((string) $request->getBody(), $request_vars);

            $jwtTokenString = $request_vars['assertion'];

            $jwtToken = (new Parser())->parse($jwtTokenString);
            $this->assertTrue($jwtToken->verify($signer, $publicKey));
        }

        GuzzleServer::flush();
    }
}
