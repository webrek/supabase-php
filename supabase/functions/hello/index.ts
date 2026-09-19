// Minimal edge function for the Functions integration test: echoes a greeting
// built from the JSON body. Served by the local stack's edge-runtime.
Deno.serve(async (req: Request): Promise<Response> => {
  let name = "world";
  if (req.method === "POST") {
    const body = await req.json().catch(() => ({}));
    if (typeof body?.name === "string" && body.name !== "") {
      name = body.name;
    }
  }

  return new Response(JSON.stringify({ message: `Hello ${name}!` }), {
    headers: { "Content-Type": "application/json" },
  });
});
