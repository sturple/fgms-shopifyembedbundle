# Shopify Embed Bundle

## Installation

** Install With Composer **

```json
{
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
             new Fgms\ShopifyEmbed\FgmsShopifyEmbedBundle();
        ]
    }
}            

```


