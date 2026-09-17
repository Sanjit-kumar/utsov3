# UTSOV Credentials Vault

`CREDENTIALS.md.enc` in this folder is an AES-256-encrypted copy of every login/API key/secret
for the UTSOV website and its integrations (FTP, PayPal, Twilio, admin.php logins, etc). It's
safe to commit and push — it's unreadable without the shared passphrase, which is **not** stored
in this repo. Get the passphrase from Sanjit directly (WhatsApp/in person), not over email or in
a GitHub issue/PR.

## To read the vault

Requires `openssl` (already included with Git for Windows / macOS / Linux — nothing extra to
install). From this folder:

```
openssl enc -d -aes-256-cbc -pbkdf2 -iter 300000 -in CREDENTIALS.md.enc -out CREDENTIALS.md -pass pass:"THE_PASSPHRASE"
```

This writes a plaintext `CREDENTIALS.md` next to it. That plaintext file is gitignored — it
never accidentally gets committed — but **delete it when you're done** rather than leaving a
decrypted copy sitting on disk indefinitely.

## To update the vault

1. Decrypt as above to get `CREDENTIALS.md`, edit it with the new/changed credential.
2. Re-encrypt with the same command, but swap `-d` (decrypt) for encrypting:
   ```
   openssl enc -aes-256-cbc -salt -pbkdf2 -iter 300000 -in CREDENTIALS.md -out CREDENTIALS.md.enc -pass pass:"THE_PASSPHRASE"
   ```
3. Commit and push `CREDENTIALS.md.enc` (only the `.enc` file — never the plaintext).
4. Delete your local plaintext `CREDENTIALS.md` copy.

## If the passphrase itself needs to change

Decrypt with the old passphrase, re-encrypt with a new one, then let everyone who has the old
passphrase know the new one out-of-band. There's no way to "rotate" this automatically since it's
a shared secret, not a per-person key — that's the tradeoff of this simple approach vs. a real
secrets manager (see the note Sanjit and Claude discussed: a password manager like Bitwarden
would give per-person access/revocation, this approach doesn't).
