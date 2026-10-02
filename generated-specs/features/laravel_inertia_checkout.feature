# language: es
Característica: Checkout Autenticado con Laravel Sanctum e Inertia Vue

  Escenario: Procesamiento de Pedido Autenticado y Renderizado Inertia
    Dado que un usuario está autenticado con sesión de Laravel Sanctum
    Cuando completa el formulario de pago en la vista Vue 3 de Inertia
    Y se envía la petición `Inertia.post('/orders', payload)`
    Entonces el `StoreOrderRequest` de Laravel valida la carga útil
    Y se crea la orden en la base de datos de forma transaccional
    Y Inertia redirige a la página de éxito `orders.show` renderizando componentes Vue
