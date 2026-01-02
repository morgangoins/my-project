# Equipment Filtering System

## How It Works

The equipment filtering system in `inventory.php` uses keyword-based matching:

1. Each equipment option has an array of keywords
2. When filtering, the system searches for ANY of these keywords in the vehicle's optional or standard equipment JSON
3. **Important**: Keywords within an option use OR logic - if ANY keyword matches, the filter matches
4. When multiple equipment options are selected, they use AND logic - ALL selected options must be present

## Common Pitfalls

### 1. Overly Broad Keywords
❌ **Bad**: Including `'painted'` as a keyword for "Hard Top, Painted"
- This will match vehicles with "Black-Painted Aluminum Wheels" even if they don't have painted hard tops

✅ **Good**: Use specific compound keywords like `'hard top, painted'` or `'painted hard top'`

### 2. Overlapping Keywords Between Options
❌ **Bad**: Including `'hard top'` in both painted and molded hard top filters
- This makes both filters match ALL hard tops, defeating the purpose

✅ **Good**: Keep keywords distinct between different equipment options

### 3. Generic Terms
❌ **Bad**: Using broad terms like `'camera'`, `'roof'`, `'package'` as standalone keywords
- These match many unrelated equipment items

✅ **Good**: Use specific phrases like `'360 camera'`, `'moonroof'`, `'black appearance package'`

## Validation

Run `php validate_filters.php` to check for potential issues:

```bash
cd /root/www
php validate_filters.php
```

This will warn about:
- Keywords used by multiple equipment options
- Potentially broad keywords that might cause false matches

## Best Practices

1. **Test filters individually** - Check that each filter only returns vehicles with the intended equipment
2. **Use specific keywords** - Prefer compound phrases over single words
3. **Validate regularly** - Run the validation script after making changes
4. **Document equipment examples** - Note the actual text that appears in window stickers
5. **Avoid generic terms** - Don't use words like "system", "package", "camera" alone

## Examples

### Good Equipment Configuration
```javascript
equipment: [
    { value: 'hard_top_painted', label: 'Hard Top, Painted', keywords: ['hard top, painted', 'hard top painted'] },
    { value: 'hard_top_molded', label: 'Hard Top, Molded-in-Color', keywords: ['molded-in-color', 'molded in color'] },
    { value: 'sound_deadening', label: 'Sound Deadening', keywords: ['sound deadening', 'sound insulation'] }
]
```

### Window Sticker Examples
- Painted: `"HARD TOP, PAINTED"`
- Molded: `"HARD TOP,GRAY MOLDED-IN-COLOR"`

## Recent Issues Fixed

- **Issue**: Vehicle appeared in both painted and molded hard top filters
- **Cause**: Molded filter included `'hard top'` keyword, matching all hard tops
- **Fix**: Removed overlapping keywords, kept them specific to each type
