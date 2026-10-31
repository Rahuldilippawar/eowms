# Fingerprint sign-in and signing (WebAuthn, Phase 11b)

People can sign in, and sign letters, with their phone's or computer's fingerprint, face or
screen-lock PIN instead of typing the password. This is WebAuthn ("passkeys"): the device keeps a
private key and only proves it has it; the fingerprint itself never leaves the device, and the ERP
stores only the public key.

## For people

- **My account → Fingerprint sign-in** (`/account/fingerprint`): add this device (a name and the
  password are asked, so a session left open cannot add a key), see the devices that can sign in,
  remove one.
- **Sign in**: "Sign in with fingerprint" on the login page (shown only where the browser can do it).
  The password keeps working.
- **Signing a letter**: the sign dialog offers "Sign with fingerprint" once the person has a device;
  the signature records the method (shown on the public signature check as "Fingerprint / device
  lock").

## Rules

- Verified on the server with PHP's openssl (no extra libraries): ES256 (P-256) and RS256 keys,
  "none" attestation, user verification required (the device must check the fingerprint / face /
  PIN, not only a tap).
- Each challenge is random, kept in the session for five minutes and used once. The browser's
  answer must name an allowed origin and the right relying party, and a key's signature counter must
  go up (a copied key would repeat it).
- Sign-in with a key is rate limited per key and per IP (an office often shares one IP), audited as a
  sign-in with `method: webauthn`; inactive accounts cannot sign in.
- Adding and removing keys are in the activity log.

## Domains

Keys belong to a domain (the "relying party id"). Decided with the owner:

| | Address |
|---|---|
| Staging (testing before cutover) | `erp.babashree.com` |
| Production (after cutover, replacing the old app) | `amd.babashree.com` |

Both use `WEBAUTHN_RP_ID=babashree.com`, so a fingerprint added on staging keeps working on
production. Set `WEBAUTHN_ORIGINS=https://erp.babashree.com,https://amd.babashree.com`. With
`WEBAUTHN_RP_ID` empty the APP_URL's host is used. WebAuthn needs HTTPS (or `localhost` in
development), and does not work on an IP address.
