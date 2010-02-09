[service]
host=li.gs
authorized=no
encode=base64
wrapper=valid-url

[store]
endpoint=/
method=get
urlparam=url
otherparams=module:ShortURL,file:Add,mode:API
response=entire-response-body

[fetch]
endpoint=/
method=get
response=scrape
scraper=/id="frame" src="(.*?)" width/ism
