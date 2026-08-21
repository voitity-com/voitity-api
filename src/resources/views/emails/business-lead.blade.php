<!doctype html>
<html lang="es">
<body>
    <h1>Nuevo lead: {{ $lead->full_name ?: 'Sin nombre' }}</h1>
    <p><strong>Email:</strong> {{ $lead->email ?: 'No informado' }}</p>
    <p><strong>Teléfono con indicativo:</strong> {{ $lead->phone ?: 'No informado' }}</p>
    <p><strong>WhatsApp con indicativo:</strong> {{ $lead->whatsapp ?: 'No informado' }}</p>
    <p><strong>Empresa:</strong> {{ $lead->company ?: 'No informada' }}</p>
    <p><strong>Sitio web:</strong> {{ $lead->website ?: 'No informado' }}</p>
    <p><strong>Problema descrito por el cliente:</strong> {{ $lead->project_summary ?: 'No informado' }}</p>
    <p><strong>Posible solución planteada por la IA (interna):</strong> {{ $lead->ai_solution_summary ?: 'Pendiente' }}</p>
</body>
</html>
