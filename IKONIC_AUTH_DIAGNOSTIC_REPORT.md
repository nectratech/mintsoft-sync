# Ikonic API Authentication Diagnostic Report

**Date:** 2026-03-09
**API Key:** b22e70af...3c5f (32 characters)

## Summary

**The API key works for some endpoints but NOT for the FBA quantity endpoints.**

## Endpoint Test Results

| Endpoint | Status | Response Type | Result |
|----------|--------|---------------|--------|
| GET /3pl/get-external-order-id | 404 | JSON | **AUTH OK** - Order not found (expected for test data) |
| POST /3pl/update-order | 404 | JSON | **AUTH OK** - Order not found (expected for test data) |
| GET /3pl/get-fba-fulfillable-quantities | 401 | HTML | **AUTH FAILED** |
| POST /3pl/get-fba-fulfillable-quantities/bulk | 401 | HTML | **AUTH FAILED** |

## Key Observations

### 1. Working Endpoints (Auth Passes)
- `GET /3pl/get-external-order-id` - Returns JSON error: `{"error":"No order found with second_ref: TEST123"}`
- `POST /3pl/update-order` - Returns JSON error: `{"error":"Order ID #TEST-DIAG-001 not found"}`

These return **proper JSON responses**, meaning:
- Authentication passed
- Request reached the application layer
- Only failed because test data doesn't exist

### 2. Failing Endpoints (Auth Rejected)
- `GET /3pl/get-fba-fulfillable-quantities` - Returns HTML 401
- `POST /3pl/get-fba-fulfillable-quantities/bulk` - Returns HTML 401

These return **HTML error pages**, meaning:
- Authentication is rejected before reaching the application
- The `X-API-Key` header is being sent correctly
- But these endpoints don't accept your API key

### 3. Alternative Auth Methods Tested
All returned `{"error":"Missing API key"}`:
- Authorization: Bearer - Not accepted
- Authorization: ApiKey - Not accepted
- api_key query parameter - Not accepted
- API-Key header - Not accepted

This confirms `X-API-Key` is the correct header name.

## Root Cause Analysis

The FBA quantity endpoints return **HTML 401 errors** (not JSON), which typically means:
1. The endpoint exists but your API key doesn't have permission
2. These endpoints require a **different API key** with FBA access
3. These endpoints are behind an additional authentication layer

## Recommendations for Ikonic Support

Contact Ikonic and ask:

1. **"Does API key `b22e70af6ca14ffc85f1efc134d53c5f` have access to the FBA fulfillable quantities endpoints?"**

2. **"The `/3pl/update-order` endpoint works with this key, but `/3pl/get-fba-fulfillable-quantities/bulk` returns 401. Are these endpoints on different permission tiers?"**

3. **"Do we need a separate API key or additional permissions for FBA inventory data?"**

4. **"Is there an IP whitelist that needs updating for the FBA endpoints?"**

## Technical Details

### Request Headers Sent (all endpoints)
```
Accept: application/json
Content-Type: application/json
X-API-Key: b22e70af6ca14ffc85f1efc134d53c5f
```

### Working Response Example (update-order)
```json
{"error":"Order ID #TEST-DIAG-001 not found"}
```
HTTP 404 - Authentication passed, order just doesn't exist

### Failing Response Example (fba-quantities)
```html
<!doctype html>
<html lang=en>
<title>401 Unauthorized</title>
<h1>Unauthorized</h1>
<p>The server could not verify that you are authorized...</p>
```
HTTP 401 - Authentication rejected before reaching application

## Next Steps

1. **Do not modify code** - the IkonicClient.php implementation is correct
2. Contact Ikonic support with this report
3. Request FBA endpoint access or a new API key with appropriate permissions
4. Once you have the correct credentials, test again with:
   ```bash
   php bin/test_ikonic_auth.php
   ```
