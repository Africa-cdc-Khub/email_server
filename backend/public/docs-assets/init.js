/* Swagger UI boot — kept external so CSP script-src 'self' allows it */
window.onload = function () {
  if (typeof SwaggerUIBundle === 'undefined') {
    document.body.innerHTML =
      '<pre style="padding:1rem;font-family:monospace">Swagger UI failed to load. ' +
      'Check that /docs-assets/swagger-ui-bundle.js is reachable.</pre>';
    return;
  }
  SwaggerUIBundle({
    url: '/api/docs.json',
    dom_id: '#swagger-ui',
    deepLinking: true,
    presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
    layout: 'BaseLayout',
    persistAuthorization: true,
    defaultModelsExpandDepth: 1,
    defaultModelExpandDepth: 1,
    tryItOutEnabled: true,
  });
};
