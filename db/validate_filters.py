#!/usr/bin/env python3
"""
Validate filter configurations against database values.

This script checks that the engine filter configurations in the frontend
match the actual engine values in the database, helping prevent filter
mismatches that cause incorrect counts or broken filtering.
"""

import sqlite3
import json
import sys
from pathlib import Path

def get_db_engines_by_model(db_path):
    """Get all engine values grouped by normalized model name."""
    conn = sqlite3.connect(db_path)
    cursor = conn.cursor()

    # Get all engines by model
    cursor.execute("""
        SELECT
            CASE
                WHEN LOWER(model) LIKE '%f-150%' THEN 'f150'
                WHEN LOWER(model) LIKE '%f-250%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-350%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-450%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-550%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-600%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-650%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-750%' THEN 'super-duty'
                WHEN LOWER(model) LIKE '%f-150 lightning%' THEN 'f150-lightning'
                WHEN LOWER(model) LIKE '%maverick%' THEN 'maverick'
                WHEN LOWER(model) LIKE '%ranger%' THEN 'ranger'
                WHEN LOWER(model) LIKE '%explorer%' THEN 'explorer'
                WHEN LOWER(model) LIKE '%expedition%' THEN 'expedition'
                WHEN LOWER(model) LIKE '%bronco%' AND LOWER(model) LIKE '%sport%' THEN 'bronco-sport'
                WHEN LOWER(model) LIKE '%bronco%' THEN 'bronco'
                WHEN LOWER(model) LIKE '%escape%' THEN 'escape'
                WHEN LOWER(model) LIKE '%mustang mach-e%' THEN 'mustang-mach-e'
                WHEN LOWER(model) LIKE '%mach-e%' THEN 'mustang-mach-e'
                WHEN LOWER(model) LIKE '%mustang%' THEN 'mustang'
                WHEN LOWER(model) LIKE '%transit%' THEN 'transit'
                ELSE LOWER(REPLACE(model, ' ', '-'))
            END as normalized_model,
            engine,
            COUNT(*) as count
        FROM vehicles_all
        WHERE engine IS NOT NULL AND engine != ''
        GROUP BY normalized_model, engine
        ORDER BY normalized_model, engine
    """)

    results = cursor.fetchall()
    conn.close()

    # Group by model
    engines_by_model = {}
    for normalized_model, engine, count in results:
        if normalized_model not in engines_by_model:
            engines_by_model[normalized_model] = []
        engines_by_model[normalized_model].append((engine, count))

    return engines_by_model

def normalize_engine_php_style(engine):
    """Apply the same normalization logic as the PHP backend."""
    engine = engine.strip().lower()

    # Engine normalizations - F-150/Lightning
    if '2.7l' in engine and 'v6' in engine:
        return '2.7L'
    if '2.7l' in engine and 'ecoboost' in engine:
        return '2.7L'
    if '3.5l' in engine and 'v6' in engine and 'ecoboost' in engine:
        return '3.5L V6 EcoBoost'
    if '3.5l' in engine and 'powerboost' in engine:
        return 'PowerBoost'
    if '3.5l' in engine and 'high-output' in engine:
        return 'High Output'
    if '5.0l' in engine and 'v8' in engine:
        return '5.0L'
    if 'dual emotor' in engine:
        return 'High Output'  # Lightning

    # Maverick engines
    if '2.0l' in engine and 'ecoboost' in engine:
        return '2.0L EcoBoost Engine'
    if '2.5l' in engine and 'hybrid' in engine:
        return '2.5L Hybrid Engine'

    # Super Duty engines
    if '6.7l' in engine and 'power stroke' in engine:
        return '6.7L Power Stroke V8'
    if '6.7l' in engine and 'high output' in engine and 'power stroke' in engine:
        return '6.7L High Output Power Stroke V8'
    if '6.8l' in engine and 'v8' in engine:
        return '6.8L V8 Gas'
    if '7.3l' in engine and 'v8' in engine:
        return '7.3L V8 Gas'

    # Explorer/Expedition engines
    if '2.3l' in engine and 'ecoboost' in engine:
        return '2.3L EcoBoost I4'
    if '3.0l' in engine and 'ecoboost' in engine:
        return '3.0L EcoBoost V6'

    # Bronco/Bronco Sport engines
    if '1.5l' in engine:
        return '1.5L I3'
    if '2.0l' in engine and 'ecoboost' in engine and 'maverick' not in engine:
        return '2.0L EcoBoost I4'

    # Mustang engines
    if '5.0l' in engine and 'v8' in engine:
        return '5.0L V8'

    # Transit engines
    if '3.5l' in engine and 'v6' in engine:
        return '3.5L V6'

    # Mach-E engines
    if 'extended range' in engine:
        return 'Extended Range Battery'

    return engine

def validate_engine_configs(db_path, html_path):
    """Validate that frontend engine configs match database values."""
    print("🔍 Validating engine filter configurations...")

    # Get database engines
    db_engines = get_db_engines_by_model(db_path)

    # Read the HTML file to extract JavaScript configs
    with open(html_path, 'r') as f:
        html_content = f.read()

    # Extract model configs from JavaScript
    configs = {}

    # Find all model config objects
    import re
    config_pattern = r'const (\w+Config) = \{([^}]*engines:\s*\[([^\]]*)\][^}]*)\};'
    matches = re.findall(config_pattern, html_content, re.DOTALL)

    for config_name, config_content, engines_content in matches:
        model_name = config_name.replace('Config', '').lower()
        if model_name == 'f150':
            model_name = 'f150'
        elif model_name == 'super_duty':
            model_name = 'super-duty'
        elif model_name == 'bronco_sport':
            model_name = 'bronco-sport'
        elif model_name == 'mustang_mach_e' or model_name == 'mach_e':
            model_name = 'mustang-mach-e'

        # Extract engine values
        engine_values = []
        engine_matches = re.findall(r"value:\s*['\"]([^'\"]*)['\"]", engines_content)
        engine_values.extend(engine_matches)

        configs[model_name] = engine_values

    # Validate each model
    issues = []
    for model, config_engines in configs.items():
        if model not in db_engines:
            print(f"⚠️  Model '{model}' has config but no vehicles in database")
            continue

        db_model_engines = db_engines[model]

        # Get normalized database engines
        normalized_db_engines = {}
        for engine, count in db_model_engines:
            normalized = normalize_engine_php_style(engine)
            normalized_db_engines[normalized] = normalized_db_engines.get(normalized, 0) + count

        # Check if config engines match normalized database engines
        config_set = set(config_engines)
        db_set = set(normalized_db_engines.keys())

        missing_from_config = db_set - config_set
        extra_in_config = config_set - db_set

        if missing_from_config or extra_in_config:
            issues.append({
                'model': model,
                'missing_from_config': list(missing_from_config),
                'extra_in_config': list(extra_in_config),
                'db_engines': dict(normalized_db_engines),
                'config_engines': config_engines
            })

    # Report issues
    if issues:
        print(f"❌ Found {len(issues)} engine configuration issues:")
        for issue in issues:
            print(f"\n🔧 Model: {issue['model']}")
            if issue['missing_from_config']:
                print(f"   Missing from config: {issue['missing_from_config']}")
            if issue['extra_in_config']:
                print(f"   Extra in config: {issue['extra_in_config']}")
            print(f"   Database engines: {list(issue['db_engines'].keys())}")
            print(f"   Config engines: {issue['config_engines']}")
    else:
        print("✅ All engine configurations are valid!")

    return len(issues) == 0

def main():
    db_path = Path(__file__).parent / 'inventory.sqlite'
    html_path = Path(__file__).parent.parent / 'html' / 'index.php'

    if not db_path.exists():
        print(f"❌ Database not found: {db_path}")
        return 1

    if not html_path.exists():
        print(f"❌ HTML file not found: {html_path}")
        return 1

    success = validate_engine_configs(str(db_path), str(html_path))
    return 0 if success else 1

if __name__ == '__main__':
    sys.exit(main())




