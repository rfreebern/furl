[service]
host=is.gd
max-chunk-size=2000
authorized=no
encode=base64
wrapper=valid-url

[store]
endpoint=/api.php
method=get
urlparam=longurl
response=entire-response-body

[fetch]
endpoint=/
method=get
response=location-header
