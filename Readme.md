# PHP SDK for Legit.Health Plus

Official PHP SDK to call **diagnosis support** on Legit.Health Plus 🩺🤖

It does for Plus what [`medical-device-sdk-php`](https://github.com/Legit-Health/medical-device-sdk-php) does for the legacy Legit.Health 2.1 device. Severity assessment stays on Legit.Health 2.1 and keeps using that SDK.

For Symfony applications, use [`medical-device-plus-sdk-symfony-bundle`](https://github.com/Legit-Health/medical-device-plus-sdk-symfony-bundle), which registers the client as a service.

## Installation

```bash
composer require legit-health/medical-device-plus-sdk
```

Requires PHP 8.4 with the `openssl` extension, and `symfony/http-client` 8.

## Usage

```php
use LegitHealth\MedicalDevicePlus\Arguments\{DiagnosisSupportArguments, EncodedImage, ImageFileFormat};
use LegitHealth\MedicalDevicePlus\Auth\{ClientCredentials, Psr6TokenCache};
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use LegitHealth\MedicalDevicePlus\Exception\RequestException;
use LegitHealth\MedicalDevicePlus\PlusClient;

$client = PlusClient::create(
    'https://plus-staging.legit.health',
    new ClientCredentials(
        issuer: 'https://id-staging.legit.health/realms/legit-health',
        clientId: 'app-backend',
        privateKey: Es256Key::fromPem($privateKey), // PEM, or the PEM encoded in base64
    ),
    tokenCache: new Psr6TokenCache($cachePool), // shared pool, see "Token cache"
);

$response = $client->diagnosisSupport(new DiagnosisSupportArguments([
    EncodedImage::fromBinary(file_get_contents('lesion-1.jpg')),
    EncodedImage::fromBinary(file_get_contents('lesion-2.png'), ImageFileFormat::Png),
]));

$response->reportId;                                       // Legithp-Report-Id header, store it
$response->studyAggregate->topHypothesis()?->identifier;   // e.g. "psoriasis"
$response->riskMetrics()->referralProbability;             // 0-100
$response->riskMetrics()->urgentReferralProbability;       // 0-100, referral in 0-48 h
$response->riskMetrics()->highPriorityReferralProbability; // 0-100, referral in 7-15 days
$response->imageAnalyses[0]->technicalSummary->isAssessable;
```

The client gets and caches the access token by itself: you never handle tokens or DPoP proofs.

### Token cache

Tokens last 300 seconds and there is no refresh token. The client asks for a new one when less than 30 seconds are left. Each token is bound to a DPoP key, so the cache stores both together.

By default tokens are kept in memory, which is enough for scripts and workers. Under PHP-FPM every worker has its own memory, so pass a `Psr6TokenCache` backed by a shared pool (Redis, APCu, Symfony `cache.app`) to reuse the token across workers.

### Errors

`diagnosisSupport()` throws:

- `AuthorizationException` when the authorization server does not issue a token. The message includes the server `error` and `error_description`.
- `RequestException` when Plus answers with an error. It carries `statusCode`, the decoded JSON `content`, the DPoP `error` from `WWW-Authenticate`, and `retryAfter` in seconds.

| Response | Meaning | What to do |
|---|---|---|
| 401 `invalid_token` | Token expired or revoked | The client already retried once with a new token |
| 401 `invalid_dpop_proof` | Proof rejected, usually clock skew over 60 s | Check the server clock |
| 403 `insufficient_scope` | The service account lacks a scope or organisation | Ask for the role in the authorization server |
| 422 | Request does not match the contract (an unknown field gives 422) | Fix the request |
| 503 with `Retry-After` (`isDeviceSaturated()`) | Device busy | Retry after `retryAfter` seconds |
| 503 without `Retry-After` (`isDeviceNotReady()`) | Device not ready yet | Retry later |
| 504 (`isDeviceTimeout()`) | Device too slow | Do not retry right away |

## How Plus authorization works

These are the answers of Alejandro Carmena, who built Plus and its authorization layer, to the questions in [app-backend#269](https://github.com/Legit-Health/app-backend/issues/269). The SDK implements all of it; this section is for whoever needs to debug it or port it.

A Python reference implementation of the same flow lives in `md-legit-health-plus`, under `tools/e2e_verification/e2e_verification/infrastructure/issuer/`.

### 1. Getting a token

Plus has no login of its own. Tokens come from the shared authorization server (Keycloak, repository `Legit-Health/authorization-server`). app-backend is registered there as the machine client `app-backend`, in staging and in production (`realm/applications/app-backend.json`).

- Grant `client_credentials`. No user, no password and no client secret: the client authenticates by signing a JWT with its private key (`private_key_jwt`, RFC 7523, ES256).
- Read `token_endpoint` from `{issuer}/.well-known/openid-configuration`, and check that the `issuer` of the document is the configured one.
- Request: `POST {token_endpoint}`, form-urlencoded, with:
  - `grant_type=client_credentials`
  - `client_id=app-backend`
  - `scope=organization:*`. The Plus scopes (`diagnosis:write`, `device:read`) are granted by default, but `organization:*` must always be requested.
  - `client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer`
  - `client_assertion=<JWT>`
- `client_assertion`: JWT signed ES256 with the client key. Claims: `iss` and `sub` = `app-backend`, `aud` = the exact issuer URL (not the token endpoint), random `jti`, `iat` = now and `exp` = `iat` + 60.
- The token request also carries a `DPoP` header (RFC 9449) signed with another P-256 key, the DPoP key. It is built as in point 2, without `ath`, with `htm=POST` and `htu` = the token endpoint.
- Response: `access_token`, `token_type: DPoP` and `expires_in: 300`. In the access token, `scope` lists only `diagnosis:write device:read`: the organisation granted by `organization:*` shows up in a separate `organization` claim (for app-backend, `["legit-health-app-backend"]`), and `cnf.jkt` binds the token to the DPoP key. No refresh token: ask for a new one when less than 30 s are left. The token is bound to the DPoP key, so cache both together; PHP-FPM workers do not share memory.

### 2. Sending the token to Plus

Every request carries two headers:

- `Authorization: DPoP <access_token>`. It is DPoP, not Bearer: with Bearer Plus answers 401.
- `DPoP: <proof>`, a new JWT for every request, signed with the same DPoP key used to get the token.
  - Header: `typ=dpop+jwt`, `alg=ES256` and `jwk` = the public key.
  - Claims: random `jti`, `htm=POST`, `htu` = the exact URL being called without query, `iat` = now (the gateway accepts ±60 s) and `ath` = SHA-256 of the access token in base64url without padding.
  - Each proof is accepted once, so a retry needs a new proof.

Failures: 401 with `WWW-Authenticate: DPoP error="invalid_token"` or `error="invalid_dpop_proof"`, and 403 with `error="insufficient_scope"`.

### 3. Diagnosis support contract

`POST {base_url}/v1.0/clinical/diagnosis-support`, JSON in camelCase. An unknown field gives 422.

Request, 1 to 5 images of the same lesion. `fileFormat` is `jpeg`, `jpg` or `png`; `identifier` (UUID or string) is optional.

```json
{"images": [{"data": "<base64>", "fileFormat": "jpeg", "colorModel": "rgb"}]}
```

Response, a `CanonicalDiagnosisReport`:

- `identifier`, `issuedAt` and `analysisDuration` (ISO 8601 duration).
- `studyAggregate.findings`: the result of all images together, with `hypotheses` (ranked conditions, each with `identifier` and `probability`), `riskMetrics` and `entropy`.
- `imageAnalyses[]`: the same per image, plus a `technicalSummary` (quality, domain, modality, `isAssessable`).
- The **referral** is in `riskMetrics`: `referralProbability`, `urgentReferralProbability` (0-48 h) and `highPriorityReferralProbability` (7-15 days), next to `anyConditionProbability`, `malignantConditionProbability` and `pigmentedConditionProbability`. All probabilities go from 0 to 100.

The response carries the `Legithp-Report-Id` header. **Store it with the company or organisation that made the request**: Plus audit logs name the client `app-backend`, not the company, and this id is what links them.

Device errors: 503 `DeviceSaturated` with `Retry-After` (retry after that time), 504 (do not retry right away) and 503 without `Retry-After` (device not ready yet).

The full schema is at https://plus-staging.legit.health/docs and `/openapi.json`.

### 4. URLs and credentials

| Environment | API | Issuer |
|---|---|---|
| Staging (preproduction) | `https://plus-staging.legit.health` | `https://id-staging.legit.health/realms/legit-health` |
| Production | `https://plus.legit.health` | `https://id.legit.health/realms/legit-health` |

Staging is not always on. If it does not answer, ask Alejandro to start it.

Credentials: generate one key pair per environment, keep the private key in the secret manager of the client application and send only the public key to Alejandro, who registers it and gives the service account its role and organisation. The private key never leaves the client application.

```bash
openssl ecparam -name prime256v1 -genkey -noout -out app-backend-staging.key
openssl pkey -in app-backend-staging.key -pubout -out app-backend-staging.pub
```

For app-backend, the private keys are in AWS SSM Parameter Store (eu-west-3) as SecureStrings, encoded in base64 on one line: `/app/backend/pre/LEGIT_HEALTH_PLUS_PRIVATE_KEY` for staging and `/app/backend/pro/LEGIT_HEALTH_PLUS_PRIVATE_KEY` for production. `Es256Key::fromPem()` accepts them as they are.

The DPoP key is a different key and is not registered anywhere: the SDK generates a new one with every token.

## Development

There is a PHP 8.4 toolchain in Docker, so nothing needs to be installed locally:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install --ignore-platform-req=php
docker run --rm -v "$PWD":/app -w /app php:8.4-cli sh -c \
  'vendor/bin/phpstan analyse && vendor/bin/phpunit --testsuite unit'   # PHPStan level 8 and unit tests
```

The integration tests call Plus staging for real. They are skipped unless these variables are set:

```bash
export PLUS_BASE_URL=https://plus-staging.legit.health
export PLUS_ISSUER=https://id-staging.legit.health/realms/legit-health
export PLUS_CLIENT_ID=app-backend
export PLUS_PRIVATE_KEY=$(AWS_PROFILE=<profile> aws ssm get-parameter --region eu-west-3 \
  --name /app/backend/pre/LEGIT_HEALTH_PLUS_PRIVATE_KEY --with-decryption --query Parameter.Value --output text)
docker run --rm -e PLUS_BASE_URL -e PLUS_ISSUER -e PLUS_CLIENT_ID -e PLUS_PRIVATE_KEY \
  -v "$PWD":/app -w /app php:8.4-cli vendor/bin/phpunit --testsuite integration
```

## Releases

Releases are git tags following semantic versioning (`1.0.0`), published on Packagist as `legit-health/medical-device-plus-sdk`.
