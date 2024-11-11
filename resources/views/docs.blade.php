<!DOCTYPE html>
<html>
<head>
    <title>{{ config('app.name') }} API Documentation</title>
    <!-- Swagger UI CSS from the cloned assets -->
    <link rel="stylesheet" type="text/css" href="{{ asset('vendor/evolve-api/swagger-ui/swagger-ui.css') }}">
    <style>
        body {
            margin:0;
            background: #fafafa;
        }
    </style>
</head>
<body>
<div id="swagger-ui"></div>

<!-- Swagger UI Bundle JS from the cloned assets -->
<script src="{{ asset('vendor/evolve-api/swagger-ui/swagger-ui-bundle.js') }}"></script>
<!-- Swagger UI Standalone Preset JS from the cloned assets -->
<script src="{{ asset('vendor/evolve-api/swagger-ui/swagger-ui-standalone-preset.js') }}"></script>
<script>
    window.onload = function() {
        const ui = SwaggerUIBundle({
            url: "{{ route('evolve-api.docs.json') }}",
            dom_id: '#swagger-ui',
            presets: [
                SwaggerUIBundle.presets.apis,
                SwaggerUIStandalonePreset
            ],
            layout: "StandaloneLayout"
        });

        window.ui = ui;
    };
</script>
</body>
</html>
