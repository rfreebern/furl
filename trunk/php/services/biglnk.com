[service]
host=biglnk.com
authorized=no
encode=base64
wrapper=valid-url

[store]
endpoint=/create.php
method=post
urlparam=longurl
otherparams=shorten:Shorten
response=scrape
scraper=/Can now be accessed through this url:<br><i>(.*?)<\/i>/ism

[fetch]
endpoint=/
method=get
response=location-header
