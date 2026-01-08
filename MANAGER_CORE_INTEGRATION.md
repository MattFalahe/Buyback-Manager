# Manager Core Integration

## Overview

As of version 1.1.0, Buyback Manager now integrates with [Manager Core](https://github.com/MattFalahe/manager-core) to provide superior pricing functionality and cross-plugin compatibility.

## What Changed?

### Before (v1.0.x)
- Buyback Manager fetched prices directly from ESI
- Maintained its own `buyback_prices` table
- No integration with other Manager Suite plugins
- Limited to Jita pricing only

### After (v1.1.0+)
- Buyback Manager uses Manager Core's pricing service via Plugin Bridge
- Shared price cache across all Manager Suite plugins
- Access to comprehensive market statistics (min, max, avg, median, percentile, stddev)
- Support for multiple markets (Jita, Amarr, Dodixie, Hek, Rens)
- Automatic type subscription for efficient price updates
- **Fallback to local pricing if Manager Core is unavailable**

## Benefits

### For Users
- **Faster Price Updates**: Shared price cache means fewer API calls
- **Better Price Data**: Access to min/max/avg/median/percentile/stddev statistics
- **Multi-Market Support**: Choose from 5 pre-configured markets
- **Cross-Plugin Integration**: Prices are consistent across Mining Manager, Structure Manager, etc.
- **Price History & Trends**: View 7-day, 30-day, 90-day price trends

### For Server Admins
- **Reduced ESI Load**: One plugin fetches prices for all
- **Lower Memory Usage**: Shared price cache instead of duplicate tables
- **Centralized Configuration**: Configure pricing once in Manager Core
- **Better Performance**: Batch fetching and concurrent requests

## How It Works

### Architecture

```
Buyback Manager
      ↓
Plugin Bridge (checks if Manager Core is available)
      ↓
Manager Core Pricing Service
      ↓
ESI Market Data (with concurrent batch fetching)
      ↓
Shared Price Cache (manager_core_market_prices table)
```

### Pricing Flow

1. **Appraisal Request**: User submits items for appraisal
2. **Type Subscription**: Buyback Manager subscribes item types to Manager Core
3. **Price Fetch**: Manager Core fetches prices from ESI (if not cached)
4. **Price Application**: Buyback Manager applies pricing rules (category/group/item modifiers)
5. **Final Price**: User sees buyback price with applied percentage

### Fallback Behavior

If Manager Core is not available:
- Buyback Manager automatically falls back to local `PricingService`
- Prices fetched directly from ESI
- Uses `buyback_prices` table for caching
- **No functionality is lost**

This ensures Buyback Manager works standalone if Manager Core is removed.

## Installation

### New Installations

1. Install Manager Core:
```bash
composer require mattfalahe/manager-core
```

2. Install Buyback Manager:
```bash
composer require mattfalahe/buyback-manager
```

3. Run migrations:
```bash
php artisan migrate
```

Manager Core will automatically discover Buyback Manager via Plugin Bridge.

### Upgrading from v1.0.x

1. Install Manager Core:
```bash
composer require mattfalahe/manager-core
php artisan migrate
```

2. Update Buyback Manager:
```bash
composer update mattfalahe/buyback-manager
php artisan migrate
```

3. **Optional**: Clean up old price data:
```sql
-- After verifying Manager Core integration works
TRUNCATE TABLE buyback_prices;
```

The `buyback_prices` table will be **deprecated** but not removed for backward compatibility.

## Configuration

### Manager Core Settings

Configure pricing in Manager Core settings:

- **Price Provider**: ESI (live market data) or SeAT Price Provider
- **Markets**: Jita, Amarr, Dodixie, Hek, Rens (or add custom markets)
- **Cache Duration**: How long to cache prices (default: 1 hour)
- **Update Frequency**: Scheduled price updates (default: every 4 hours)

### Buyback Manager Settings

Buyback-specific settings remain unchanged:

- **Base Percentage**: Default buyback percentage (e.g., 90%)
- **Pricing Rules**: Category/group/item-level price modifiers
- **Price Source**: Currently only supports Jita (more markets coming soon)

## Advanced Features

### Type Subscriptions

Manager Core tracks which plugins need which item prices. When Buyback Manager appraises items:

```php
// Automatic subscription
$bridge->call('ManagerCore', 'pricing.subscribeTypes', [
    'BuybackManager',  // Plugin name
    $typeIds,          // Array of type IDs
    'jita',            // Market
    5                  // Priority (higher = more important)
]);
```

This ensures Manager Core always keeps buyback-relevant items priced.

### Pricing Statistics

Manager Core provides comprehensive statistics:

```php
$priceData = $bridge->call('ManagerCore', 'pricing.getPrice', [$typeId, 'jita', 'sell']);

// Returns:
[
    'price_min' => 5.50,      // Lowest sell order
    'price_max' => 6.25,      // Highest sell order
    'price_avg' => 5.85,      // Volume-weighted average
    'price_median' => 5.80,   // Median price
    'price_percentile' => 5.60, // 5th percentile
    'price_stddev' => 0.25,   // Standard deviation
    'volume' => 1250000,      // Total volume
    'order_count' => 47,      // Number of orders
    'updated_at' => '2026-01-08 14:30:00'
]
```

Buy back Manager uses `price_min` (lowest sell) for conservative pricing.

### Price Trends

Coming soon: Access to 7-day, 30-day, 90-day price trends:

```php
$trend = $bridge->call('ManagerCore', 'pricing.getTrend', [$typeId, 'jita', 7]);

// Returns: 'rising', 'falling', or 'stable'
```

## Troubleshooting

### Check Integration Status

Run the diagnostic command:

```bash
php artisan manager-core:diagnose-bridge
```

This shows:
- Registered plugins
- Plugin capabilities
- Integration health

### View Logs

Check Laravel logs for integration messages:

```bash
tail -f storage/logs/laravel.log | grep "Buyback Manager"
```

Look for:
- `[Buyback Manager] Using Manager Core for pricing` ✅ Integration active
- `[Buyback Manager] Manager Core not available, using local pricing` ⚠️ Fallback mode

### Force Price Update

Update prices manually:

```bash
php artisan manager-core:update-prices --market=jita
```

### Clear Cache

If prices seem stale:

```bash
php artisan cache:clear
php artisan manager-core:update-prices --market=jita
```

## Migration Timeline

### v1.1.0 (Current)
- ✅ Manager Core integration added
- ✅ Automatic fallback to local pricing
- ✅ `buyback_prices` table deprecated but retained

### v1.2.0 (Planned)
- Multi-market support in Buyback Manager
- Price trend integration
- Enhanced statistics display

### v2.0.0 (Future)
- Remove `buyback_prices` table
- Remove local `PricingService`
- Manager Core becomes required dependency (not optional)

## API Reference

### Plugin Bridge Capabilities

Buyback Manager uses these Manager Core capabilities:

```php
// Get single item price
$bridge->call('ManagerCore', 'pricing.getPrice', [$typeId, $market, $priceType]);

// Get multiple item prices
$bridge->call('ManagerCore', 'pricing.getPrices', [$typeIds, $market, $priceType]);

// Subscribe types for automatic updates
$bridge->call('ManagerCore', 'pricing.subscribeTypes', [$pluginName, $typeIds, $market, $priority]);

// Get price trend
$bridge->call('ManagerCore', 'pricing.getTrend', [$typeId, $market, $days]);
```

## Support

For issues related to:
- **Pricing functionality**: Open issue in [Manager Core](https://github.com/MattFalahe/manager-core/issues)
- **Buyback-specific features**: Open issue in [Buyback Manager](https://github.com/MattFalahe/Buyback-Manager/issues)
- **Plugin Bridge integration**: Open issue in [Manager Core](https://github.com/MattFalahe/manager-core/issues)

## Credits

Manager Core integration designed and implemented by Matt Falahe.

Inspired by go-evepraisal's architecture and EVE Online's market mechanics.
