# SolwedES - FacturaScripts Plugin

FacturaScripts plugin for Portal SOLWED integration.

## Features

- **Stripe Integration** - Webhooks, payments, subscriptions
- **Domain Management** - DonDominio sync, registration, renewal
- **Service Contracts** - Subscription lifecycle management
- **Plesk Integration** - Hosting server API
- **SSO** - Secure service access tokens

## Installation

1. Copy this folder to `Plugins/` in FacturaScripts
2. Run `composer install` in this directory
3. Enable plugin in FacturaScripts admin
4. Configure settings (Stripe, DonDominio)

## Configuration

### Stripe Settings
FacturaScripts > Tools > Settings > Stripe

- `stripe_secret_key` - API secret key
- `stripe_webhook_secret` - Webhook signing secret
- `crear_factura` - Auto-create invoices
- `crear_albaran` - Auto-create delivery notes

### DonDominio Settings
FacturaScripts > Tools > Settings > DonDominio

- `dondominio_api_user` - API username
- `dondominio_api_pass` - API password
- `dondominio_auto_sync` - Enable auto-sync

## Structure

```
SolwedES/
├── Controller/     # HTTP controllers (StripeWebhook, API endpoints)
├── Model/          # Data models (Servicio, Dominio, PagoStripe, etc.)
├── Lib/            # Helper libraries (StripeHelper, DonDominioHelper)
├── Extension/      # FS extensions (Contacto)
├── Table/          # Database schemas (XML)
├── XMLView/        # UI definitions (XML)
├── Init.php        # Plugin initialization
└── Cron.php        # Scheduled tasks
```

## Key Components

### Controllers
- `StripeWebhook.php` - Main webhook handler
- `APIDominio.php` - Domain REST API
- `List*.php` / `Edit*.php` - CRUD interfaces

### Models
- `Servicio` - Service catalog
- `Dominio` - Domain records (linked to ContratServicio)
- `PagoStripe` - Payment records
- `ContratServicio` - Service contracts (primary subscription model)
- `AccesoServicio` - SSO access

### Libraries
- `StripeHelper` - Stripe API wrapper
- `DonDominioHelper` - DonDominio API wrapper
- `ServiceAccessManager` - SSO management
- `EmailManager` - Notifications

## Documentation

Full documentation: [../docs/SOLWEDES-PLUGIN.md](../docs/SOLWEDES-PLUGIN.md)

## Webhook URL

```
POST https://erp.solwed.es/StripeWebhook
```

## Cron Jobs

- Domain sync from DonDominio (hourly)
- Token cleanup (hourly)
- Service expiration check (daily)
- Plesk sync (every 6 hours)
