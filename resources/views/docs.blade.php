<!DOCTYPE html>
<html>
<head>
    <title>{{ config('evolve-api.docs.title', 'API Documentation') }}</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@latest/swagger-ui.css" />
    <script src="https://unpkg.com/swagger-ui-dist@latest/swagger-ui-bundle.js"></script>
</head>
<body>
<div id="swagger-ui"></div>
<script>
    window.onload = () => {
        const ui = SwaggerUIBundle({
            url: "{{ route('evolve-api.docs.json') }}",
            dom_id: '#swagger-ui',
            deepLinking: true,
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIBundle.presets.modals
            ],
        });
    };
</script>
</body>
</html>
