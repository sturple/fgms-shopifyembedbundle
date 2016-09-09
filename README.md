# Shopify Embed Bundle

## Installation

** Install With Composer **

```json
{
    "repositories" : [
        {
            "type" : "git",
            "url" : "https://github.com/sturple/fanferret-questionbundle"
        }
    ],    
   "require": {
       "sturpe/fgms-shopifyembedbundle": "dev-master"
   }
}

```

and then execute

```json
$ composer update
```


## Configuration

**Add to ```app/AppKernal.php``` file**

```php

class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = [
            ...
             new FGMS\ShopifyEmbed\FGMSShopifyEmbedBundle();
        ]
    }
}            

```


