# Electre cover-image API

A small PHP endpoint that returns a book's cover image from the
[Electre API](https://docs.electre-ng.com/) for a given EAN-13 or ISBN-10.
It is meant to sit behind Primo VE (or any other consumer) as the thumbnail
source, so that Electre credentials never leave the server.

```
GET /api.php?ean=9782915215977   ->  200  image/jpeg   (the cover)
                                     400  bad / missing ean
                                     404  no cover available
```

## Configuration

All configuration is read from environment variables.

| Variable                | Required | Default          | Description |
| ----------------------- | :------: | ---------------- | ----------- |
| `ELECTRE_USERNAME`      | yes      | –                | Electre account username |
| `ELECTRE_PASSWORD`      | yes      | –                | Electre account password |
| `ALLOWED_IMAGE_HOST`    | no       | `electre-ng.com` | Cover URLs must be HTTPS on this host or a sub-domain of it |


## Run with Docker

> [!IMPORTANT]
> The endpoint api.php does not include authentication.

```bash
docker build -t electre-cover-api .
docker run --rm -p 8080:80 -e ELECTRE_USERNAME="…" -e ELECTRE_PASSWORD="…" electre-cover-api
```
## Testing

```bash
curl -I "http://localhost:8080/api.php?ean=9782915215977"   # -> 200, image/jpeg
```

## Primo VE integration

Configure the thumbnail template to point at `…/api.php?ean={isbn}`. See
[Configuring Thumbnail Templates for Primo VE](https://knowledge.exlibrisgroup.com/Primo/Product_Documentation/020Primo_VE/025Display_Configuration/Configuring_Thumbnail_Templates_for_Primo_VE).

## Origin & licence

`api.php` is derived from a GPLv3 project by the *Université des Antilles*

## Further reading

* Electre API: <https://docs.electre-ng.com/>
* Original project: <https://github.com/scdantilles/Electre>
