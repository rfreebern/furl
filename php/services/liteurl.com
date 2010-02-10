[service]
host=liteurl.com
authorized=no
encode=base64
wrapper=valid-url

[store]
endpoint=/
method=post
urlparam=url
otherparams=mode:shrink
response=scrape
scraper=/<blockquote><a href="(.*?)" target/ism

[fetch]
endpoint=/
method=get
response=location-header
