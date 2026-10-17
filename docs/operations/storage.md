# Storage

!!! info "Ficha documental"
    - Estado: `vigente`
    - Tipo: `how-to`
    - Audiencia: desarrolladores que agregan lectura o escritura de archivos
    - Fuente verificable: `config/filesystems.php`, `.env.example` y `docs/operations/local-services.md`

Toda operación de archivos debe pasar por el facade `Storage` con un disk nombrado.

## Regla `Storage::disk()`

```php
Storage::disk('s3')->put('reports/export.csv', $contents);
Storage::disk('local')->get('temp/file.txt');
```

Está prohibido escribir o leer archivos con rutas absolutas en código de módulo:

```php
file_put_contents('/var/www/storage/reports/export.csv', $contents);
fopen('/absolute/path', 'w');
```

Los nuevos disks, como `exports` o `media`, deben definirse en `config/filesystems.php` usando variables de entorno. No hardcodees buckets ni paths en módulos.

## MinIO vs AWS S3

El desarrollo local usa MinIO como servicio S3-compatible.

| Entorno | Variables clave |
| --- | --- |
| MinIO local | `AWS_USE_PATH_STYLE_ENDPOINT=true`, `AWS_ENDPOINT` apuntando a MinIO |
| AWS S3 real | `AWS_USE_PATH_STYLE_ENDPOINT=false`, sin `AWS_ENDPOINT` |

No se requieren cambios de código para alternar entre MinIO y AWS S3; solo cambian variables de entorno y configuración.

## Endpoints locales

- API desde el host: `http://127.0.0.1:9000`.
- Consola desde el host: `http://127.0.0.1:9001`.
- Endpoint desde contenedores Docker: `AWS_ENDPOINT=http://minio:9000`.

## Ver también

- [Servicios locales](local-services.md)
- [Excepciones operativas](exceptions.md)
