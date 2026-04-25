### Github Repository Fine Granted Token
```text
Generate fine-grained PAT (recommended type):

1. https://github.com/settings/personal-access-tokens/new
2. Token name: laravel-data-access-wrapper-read
3. Resource owner: your account (hassamulhaq)
4. Expiration: 90 days max — calendar reminder to rotate
5. Repository access: Only select repositories → pick laravel-data-access-layer
6. Repository permissions:
- Contents: Read-only
- Metadata: Read-only (auto-required)
- leave rest as No access
7. Generate token
8. Copy github_pat_xxx... — shown once. Save in password manager.

Devs use it locally:                                                                                                                                                                                                       
`composer config --global --auth github-oauth.github.com github_pat_xxx...`                                                                                                                                                  
Stored in ~/.composer/auth.json. Composer reads automatically.

Test:
cd /var/www/html/rms-backend
composer update hassam/data-access-wrapper
# should fetch without asking auth

CI: same token as repository secret PACKAGE_READ_PAT:

`- run: composer config --global --auth github-oauth.github.com ${{ secrets.PACKAGE_READ_PAT }}``
``- run: composer install --no-interaction --prefer-dist`

Revoke / rotate: https://github.com/settings/personal-access-tokens → click token → Revoke. Generate new, redistribute.
```