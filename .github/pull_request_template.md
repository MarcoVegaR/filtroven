## Resumen

- 

## Validación

- [ ] Tests o comandos relevantes ejecutados.
- [ ] `mkdocs build --strict` ejecutado si el cambio toca documentación o navegación documental.
- [ ] El workflow `docs` debe pasar si el cambio toca `docs/`, `mkdocs.yml` o `requirements.txt`.

## Checklist documental

- [ ] Declaré impacto documental: no aplica, README, `docs/`, ADR, referencia derivable/generada, PRD o gobernanza.
- [ ] La documentación visible en MkDocs está en español; mantuve en inglés solo nombres propios, APIs, comandos, clases o conceptos estándar.
- [ ] Si cambié navegación o títulos publicados, actualicé `mkdocs.yml` y revisé enlaces internos tocados.
- [ ] Actualicé `docs/` o ADRs si el cambio modifica comportamiento, arquitectura, operación o comandos relevantes.
- [ ] Todo documento publicado declara estado, tipo documental, audiencia y fuente verificable.
- [ ] El estado documental sigue claro: vigente, historico, reemplazado, borrador o deprecated.
- [ ] El tipo documental sigue claro: tutorial, how-to, referencia, explicacion, ADR, auditoria, PRD o reporte.
- [ ] Si agregué o cambié una decisión arquitectónica, actualicé un ADR con estado proposed, accepted, superseded o rejected.
- [ ] Si agregué una página publicada, revisé si debe incluirse en `mkdocs.yml`.
- [ ] No publiqué credenciales, passwords, secrets, tokens reales ni datos sensibles.
- [ ] No dupliqué catálogos que pueden derivarse con comandos como `php artisan route:list`, `php artisan list` o `php artisan config:show`.
