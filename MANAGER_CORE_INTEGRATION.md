# Manager Core Integration

## Overview

**As of version 1.0.0, Buyback Manager requires [Manager Core](https://github.com/MattFalahe/manager-core) for all pricing and appraisal functionality.**

This integration provides superior pricing, item parsing, SDE validation, and a seamless user experience.

## Why Manager Core?

Buyback Manager focuses on what it does best: **buyback contract management and corporate pricing rules**. All market pricing, item parsing, and data fetching is handled by Manager Core.

### Division of Responsibilities

| Feature | Manager Core | Buyback Manager |
|---------|--------------|-----------------|
| **Item Parsing** | ✅ All formats (cargo scan, assets, EFT, etc.) | ❌ |
| **SDE Validation** | ✅ Validates item names against EVE database | ❌ |
| **Price Fetching** | ✅ ESI market data with concurrent fetching | ❌ |
| **Price Caching** | ✅ Shared cache for all plugins | ❌ |
| **Market Support** | ✅ Jita, Amarr, Dodixie, Hek, Rens | ❌ |
| **Corporate Rules** | ❌ | ✅ Base %, category %, group %, item % |
| **Contract Tracking** | ❌ | ✅ Buyback contract management |
| **Profit Tracking** | ❌ | ✅ Corporation profit statistics |

##Installation

### Requirements

- Manager Core v1.0.0 or higher

### Installation Steps

1. **Install Manager Core first:**
```bash
composer require mattfalahe/manager-core
php artisan vendor:publish --tag=manager-core-migrations
php artisan migrate
```

2. **Then install Buyback Manager:**
```bash
composer require mattfalahe/buyback-manager
php artisan vendor:publish --tag=buyback-manager-migrations
php artisan migrate
```

3. **Configure Manager Core pricing:**
   - Go to Manager Core → Settings
   - Choose price provider (ESI recommended)
   - Configure markets (Jita default)

4. **Configure Buyback Manager:**
   - Go to Buyback Manager → Settings
   - Create corporation buyback settings
   - Set base percentage and modifiers

## How It Works

### Appraisal Flow

```
User pastes items in Buyback Manager
         ↓
Buyback Manager sends raw input to Manager Core
         ↓
Manager Core:
  1. Parses items (cargo scan, assets, etc.)
  2. Validates against SDE (EVE database)
  3. Fetches live prices from ESI
  4. Returns 100% market value
         ↓
Buyback Manager:
  1. Applies corporation base percentage
  2. Applies category modifiers (e.g., Ore: 95%)
  3. Applies group modifiers (e.g., Compressed Ore: 98%)
  4. Applies item modifiers (e.g., Tritanium: 85%)
  5. Shows buyback price to user
```

### Example

**Items pasted:**
```
Tritanium    1000000
Pyerite      500000
```

**Manager Core returns:**
- Tritanium: 6.50 ISK each = 6,500,000 ISK total (100% market)
- Pyerite: 12.00 ISK each = 6,000,000 ISK total (100% market)
- **Total Market Value: 12,500,000 ISK**

**Buyback Manager applies rules:**
- Corporation base: 90%
- Tritanium modifier: 85% (overrides base)
- Pyerite modifier: 95% (overrides base)

**Final buyback:**
- Tritanium: 5.525 ISK each = 5,525,000 ISK (85%)
- Pyerite: 11.40 ISK each = 5,700,000 ISK (95%)
- **Total Buyback Value: 11,225,000 ISK (89.8% of market)**

## Features

### Appraisal Tab

The **Appraisal** tab in Buyback Manager provides:

✅ **Powered by Manager Core** - All parsing and pricing handled by Manager Core
✅ **Multi-format parsing** - Cargo scan, assets, inventory copy-paste
✅ **SDE Validation** - Invalid items are detected and reported
✅ **Live ESI Prices** - Fetched in real-time with concurrent requests
✅ **Corporate Modifiers** - Automatic application of buyback rules
✅ **Item Breakdown** - See market price vs buyback price per item
✅ **Loading Animation** - Fun messages while prices are being fetched

### API Reference

Buyback Manager uses these Manager Core capabilities via Plugin Bridge:

#### `appraisal.create`
Creates a full appraisal with parsing and pricing.

```php
$appraisal = $bridge->call('ManagerCore', 'appraisal.create', [
    $rawInput,  // User's pasted items
    [
        'market' => 'jita',
        'price_percentage' => 100,  // Get 100% market value
    ]
]);

// Returns:
// - $appraisal->items (collection of AppraisalItem models)
// - Each item has: type_id, type_name, quantity, sell_price, group_id, category_id
```

## Migration Guide (v0.x to v1.0)

### Breaking Changes

❌ **Removed:**
- `PricingService` class
- `BuybackPrice` model
- `buyback_prices` database table
- `UpdatePrices` job
- Local ESI price fetching

✅ **Added:**
- Manager Core requirement (mandatory)
- Plugin Bridge integration
- Appraisal tab with Manager Core
- Automatic price subscription

### Upgrade Steps

1. Install Manager Core (see Installation above)
2. Update Buyback Manager to v1.0.0
3. Run migrations: `php artisan migrate`
4. Old `buyback_prices` table will be automatically dropped
5. All existing buyback settings and contracts are preserved
6. Corporate pricing rules (modifiers) are preserved

### Data Migration

- ✅ Buyback settings → **Preserved**
- ✅ Category/Group/Item modifiers → **Preserved**
- ✅ Contract history → **Preserved**
- ❌ `buyback_prices` table → **Removed** (Manager Core handles all pricing)

## Configuration

### Buyback Settings

In Buyback Manager → Settings:

- **Corporation**: Which corporation this buyback is for
- **Enabled**: Toggle buyback on/off
- **Base Percentage**: Default buyback % (e.g., 90%)
- **Price Source**: Market to use (Jita, Amarr, etc.) - fetched from Manager Core

### Pricing Modifiers

Set different percentages for:

- **Categories** (e.g., Ore: 95%, Minerals: 92%)
- **Groups** (e.g., Compressed Ore: 98%)
- **Individual Items** (e.g., Tritanium: 85%)

Priority order: **Item > Group > Category > Base**

## Troubleshooting

### "Manager Core not available"

**Cause**: Manager Core plugin is not installed or not registered.

**Solution**:
```bash
composer require mattfalahe/manager-core
php artisan migrate
php artisan cache:clear
```

### Appraisal shows "No valid items found"

**Cause**: Items aren't recognized in EVE's database (SDE).

**Solution**: Check spelling of item names. Manager Core validates against official EVE item names.

### Prices are 0.00 ISK

**Cause**: Prices haven't been fetched yet.

**Solution**: This shouldn't happen in v1.0+ as prices are fetched immediately. If it does:
```bash
php artisan manager-core:update-prices --market=jita
```

### Error: "Class ManagerCore\Services\PluginBridge not found"

**Cause**: Manager Core is not properly installed.

**Solution**:
```bash
composer install
composer dump-autoload
php artisan cache:clear
```

## Performance

### Concurrent Fetching

Manager Core fetches prices for multiple items concurrently (10 at a time), providing **~10x faster** price updates compared to sequential fetching.

### Shared Cache

All plugins using Manager Core share the same price cache, eliminating duplicate ESI calls and reducing server load.

### Smart Subscriptions

When you create an appraisal, items are automatically subscribed for future updates. Manager Core's scheduled job keeps prices fresh.

## Support

- **Issues**: https://github.com/MattFalahe/Buyback-Manager/issues
- **Manager Core Issues**: https://github.com/MattFalahe/manager-core/issues
- **Documentation**: https://github.com/MattFalahe/Buyback-Manager/wiki

## License

GPL-2.0-or-later
