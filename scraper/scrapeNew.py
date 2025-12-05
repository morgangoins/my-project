import requests
import json
import math
import csv
import sys
import os
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from requests.exceptions import RequestException

base_url = "https://www.fordfairfield.com/apis/widget/INVENTORY_LISTING_DEFAULT_AUTO_NEW:inventory-data-bus1/getInventory"

params = {
    "listFormat": "window",
    "sortByField": "year",
    "sortOrder": "desc",
    "pageSize": "100",
    "start": "0",
    "filters": "",
    "accountId": "fairfieldfairfieldfordfd",
    "type": "new",
    "lang": "en"
}

# Fetch first page with error handling
try:
    r = requests.get(base_url, params=params, timeout=30)
    r.raise_for_status()  # Raise an error for bad status codes
    data = r.json()
    
    # Check if response has expected structure
    if 'inventory' not in data or 'pageInfo' not in data:
        print("Error: API response missing expected fields")
        print(f"Response: {data}")
        sys.exit(0)  # Exit gracefully to avoid crash loop
    
    vehicles = data['inventory']
    total = data['pageInfo']['totalCount']
    page_size = int(params['pageSize'])
    start = page_size
    
    # Fetch remaining pages
    while start < total:
        params['start'] = str(start)
        try:
            r = requests.get(base_url, params=params, timeout=30)
            r.raise_for_status()
            page_data = r.json()
            if 'inventory' in page_data:
                vehicles += page_data['inventory']
            start += page_size
        except (RequestException, json.JSONDecodeError) as e:
            print(f"Warning: Failed to fetch page at start={start}: {e}")
            start += page_size  # Continue to next page
            continue
            
except RequestException as e:
    print(f"Error: Failed to connect to API: {e}")
    sys.exit(0)  # Exit gracefully
except json.JSONDecodeError as e:
    print(f"Error: Failed to parse JSON response: {e}")
    try:
        print(f"Response text: {r.text[:500]}")
    except:
        pass
    sys.exit(0)  # Exit gracefully
except Exception as e:
    print(f"Error: Unexpected error occurred: {e}")
    sys.exit(0)  # Exit gracefully

# Deduplicate by VIN
unique_vehicles = {v['vin']: v for v in vehicles}.values()

# Define all fields to extract
fields = [
    'VIN', 'Year', 'Make', 'Model', 'Trim', 'Exterior Color', 'Interior Color', 'Odometer', 
    'MSRP', 'Sale Price', 'Retail Price', 'Stock Number', 'Fuel Economy', 'Engine', 
    'Transmission', 'Drive Line', 'Body Style', 'Fuel Type', 'Condition', 'Inventory Date', 
    'Chrome ID', 'Model Code', 'Package Code', 'City Fuel Economy', 'Highway Fuel Economy', 
    'Incentive IDs', 'Option Codes', 'Photo URLs', 'Vehicle Link'
]

# Extract all available data
rows = []
for v in unique_vehicles:
    attrs = {a['name']: a['value'] for a in v.get('attributes', [])}
    tracking_attrs = {a['name']: a['value'] for a in v.get('trackingAttributes', [])}  # Handle missing trackingAttributes
    pricing = v.get('pricing', {})
    # Extract photo URLs
    photo_urls = [img['uri'] for img in v.get('images', [])]
    # Construct full vehicle link
    vehicle_link = f"https://www.fordfairfield.com{v.get('link', '')}"
    row = {
        'VIN': v.get('vin', ''),
        'Year': v.get('year', ''),
        'Make': v.get('make', ''),
        'Model': v.get('model', ''),
        'Trim': v.get('trim', ''),
        'Exterior Color': attrs.get('exteriorColor', ''),
        'Interior Color': attrs.get('interiorColor', ''),
        'Odometer': v.get('odometer', '0'),
        'MSRP': pricing.get('retailPrice', ''),
        'Sale Price': next((p['value'] for p in pricing.get('dprice', []) if p.get('typeClass') == 'salePrice'), ''),
        'Retail Price': next((p['value'] for p in pricing.get('dprice', []) if p.get('typeClass') == 'internetPrice'), ''),
        'Stock Number': attrs.get('stockNumber', ''),
        'Fuel Economy': attrs.get('fuelEconomy', ''),
        'Engine': attrs.get('engine', ''),
        'Transmission': attrs.get('transmission', ''),
        'Drive Line': tracking_attrs.get('driveLine', ''),
        'Body Style': v.get('bodyStyle', ''),
        'Fuel Type': v.get('fuelType', ''),
        'Condition': v.get('condition', ''),
        'Inventory Date': v.get('inventoryDate', ''),
        'Chrome ID': v.get('chromeId', ''),
        'Model Code': v.get('modelCode', ''),
        'Package Code': tracking_attrs.get('packageCode', ''),
        'City Fuel Economy': tracking_attrs.get('cityFuelEconomy', ''),
        'Highway Fuel Economy': tracking_attrs.get('highwayFuelEconomy', ''),
        'Incentive IDs': ','.join(v.get('incentiveIds', [])),
        'Option Codes': ','.join(v.get('optionCodes', [])),
        'Photo URLs': ','.join(photo_urls),
        'Vehicle Link': vehicle_link
    }
    rows.append(row)

# Save to CSV in the same directory as this script, with a timestamped filename.
# Before writing, remove any inventory CSVs older than 7 days.
base_dir = os.path.dirname(os.path.abspath(__file__))

# Delete previously generated inventory CSV files older than 7 days
now = datetime.now(ZoneInfo("America/Los_Angeles"))
cutoff = now - timedelta(days=7)

for existing in os.listdir(base_dir):
    if existing.startswith("inventoryNew-") and existing.endswith(".csv"):
        path = os.path.join(base_dir, existing)
        try:
            mtime = datetime.fromtimestamp(os.path.getmtime(path), ZoneInfo("America/Los_Angeles"))
            if mtime < cutoff:
                os.remove(path)
        except OSError as e:
            print(f"Warning: could not inspect/remove old inventory file {existing}: {e}")

timestamp = datetime.now(ZoneInfo("America/Los_Angeles")).strftime("%Y%m%d-%H%M%S")
filename = f"inventoryNew-{timestamp}.csv"
csv_path = os.path.join(base_dir, filename)

with open(csv_path, 'w', newline='') as f:
    writer = csv.DictWriter(f, fieldnames=fields)
    writer.writeheader()
    writer.writerows(rows)

print(f"Saved {len(rows)} unique vehicles to {csv_path}")