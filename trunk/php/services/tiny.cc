[service]
host=tiny.cc
authorized=no
encode=base64
wrapper=valid-url

[store]
endpoint=/
method=post
urlparam=fullurl
otherparams=rnd:#rand/100000000\,999999999
response=scrape
scraper=/copy\("(.*?)"\);/ism

[fetch]
endpoint=/
method=get
response=location-header
