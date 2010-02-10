[service]
host=doiop.com
authorized=no
encode=base64
wrapper=valid-url

[store]
endpoint=/
method=post
urlparam=url
otherparams=pkey:,Submit:Make It!
response=scrape
scraper=/&nbsp;<a href="(.*?)" target/ism

[fetch]
endpoint=/
method=get
response=location-header
