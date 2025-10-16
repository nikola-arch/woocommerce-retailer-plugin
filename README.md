# Retailer Plugin 

Plugin developed for Retailer Dropshippers.

## Changelog

- 2025-10-07 version 1.0.0


### 1. Creating a release:

Before creating a release be sure to commit all of your changes.

Creating a release means creating a Git tag and pushing it to the remote repository.

If the tag already exists in the remote or in the local Git repository the script will stop with the execution.

You can do this by executing the ```release.sh``` shell script.

### 2. Adding the plugin to some Composer managed WordPress project like Bedrock:

Edit your Bedrock `composer.json` file, add your repository and plugin.

Add the repository to the `repositories` section and the plugin with the version number to the `require` section in the `composer.json` file:

```
"repositories": [
    {
      "type": "vcs",
      "url": "git@github.com:shopwoo/{retailer}-woocommerce-plugin.git"
    }
],
"require": {
    "shopwoo/{retailer}-woocommerce-plugin.git": "X.Y.Z"
}
```

or if you have a Composer registry:

```
"repositories": [
    {
      "type": "composer",
      "url": "[REPOSITORY URL]"
    }
],
"require": {
    "shopwoo/{retailer}-woocommerce-plugin.git": "X.Y.Z"
}
```

Run `composer require` to get the latest plugin version.

If this does not work try:

Delete the ```composer.lock``` file and  run `composer install` to get the latest plugin version.