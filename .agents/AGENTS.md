# ROKO project scope

- ROKO is the active extension and the default target for every future change in this repository.
- When a request refers to the assistant, module, extension, admin panel, prompts, agents, or package without naming a product, interpret it as ROKO.
- Make implementation changes under `dist/ROKO.ocmod/` and rebuild `dist/ROKO.ocmod.zip` when packaging is affected.
- Keep the duplicated OpenCart admin trees under `upload/admin/` and `upload/admintomcat/` synchronized.
- Do not modify `opencart_extension/ai_shopping_assist_oc4/` or other AI Shopping Assistant code unless the user explicitly asks for that product by name.
