# Analytics Module

The SmartTrack analytics dashboard combines SQL-calculated attendance, payment, trainee, training, and assessment statistics with optional Gemini-generated interpretation.

## Backend endpoints

- `GET /api/analytics` - Complete role-filtered analytics report.
- `GET /api/analytics/ai-insights` - Return the cached Gemini insight for the current filters.
- `POST /api/analytics/ai-insights/generate` - Generate or retrieve a Gemini insight.

MySQL and PHP calculate every statistic. Gemini receives only aggregate statistics and anonymized trainee labels; it never receives names, payment references, JWTs, passwords, or API keys.

## Gemini configuration

Configure these server-side environment variables:

```text
GEMINI_API_KEY=your_google_ai_studio_api_key
GEMINI_MODEL=gemini-3.5-flash-lite
```

Use a model supported by the API key in Google AI Studio. The default is the documented stable `gemini-3.5-flash-lite`; changing it requires redeploying the server environment.

## Privacy and caching

- Insights are cached for one hour using a hash of the role-filtered analytics and selected model.
- AI generation is limited to five requests per user per minute.
- The `GEMINI_API_KEY` is transmitted only to Google's REST API by the PHP backend.
- Browser-facing responses contain generated text, never the Gemini credential.
