# Azure App Registration — Setup Guide
# SpoX+AI — BHAK & BHAS Steyr

This guide is for school IT to set up the Microsoft Entra ID (Azure AD) App Registration.

## Step 1: Create App Registration

1. Go to [portal.azure.com](https://portal.azure.com) and sign in with the school admin account.
2. Navigate to **Azure Active Directory** → **App registrations** → **New registration**.
3. Fill in:
   - **Name**: `SpoX+AI`
   - **Supported account types**: `Accounts in this organizational directory only (Single tenant)`
   - **Redirect URI**: `Web` → `https://spox.hak-steyr.at/api/auth.php?action=callback`
4. Click **Register**.

## Step 2: Note the IDs

After registration, copy these values to `config/.env.php`:
- **Application (client) ID** → `MS_CLIENT_ID`
- **Directory (tenant) ID** → `MS_TENANT_ID`

## Step 3: Create Client Secret

1. In the app registration, go to **Certificates & secrets** → **New client secret**.
2. Description: `SpoX+AI Production`
3. Expiry: 24 months (note: must be renewed before expiry!)
4. Click **Add** and immediately copy the **Value** → `MS_CLIENT_SECRET`

> ⚠️ The secret value is only shown once. Copy it immediately!

## Step 4: API Permissions

1. Go to **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions**.
2. Add: `openid`, `profile`, `email`, `User.Read`
3. Click **Grant admin consent** for the school tenant.

## Step 5: Configure in .env.php

```php
define('MS_CLIENT_ID',     'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('MS_CLIENT_SECRET', 'your-secret-value-here');
define('MS_TENANT_ID',     'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('MS_REDIRECT_URI',  'https://spox.hak-steyr.at/api/auth.php?action=callback');
define('MS_ALLOWED_DOMAIN','hak-steyr.at');
```

## Step 6: Test

1. Visit `https://spox.hak-steyr.at`
2. Click "Log in"
3. Sign in with a `@hak-steyr.at` account
4. Verify you are redirected back and see your initial in the top-right corner.

## Troubleshooting

| Error | Cause | Fix |
|-------|-------|-----|
| `domain_not_allowed` | Non-hak-steyr.at account | Only school accounts allowed |
| `invalid_state` | Session expired | Try again |
| `token_exchange_failed` | Wrong CLIENT_SECRET | Re-check secret in .env.php |
| Redirect URI mismatch | Azure config wrong | Ensure exact URI match in Azure portal |
