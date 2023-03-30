# T3 cowriter

Did you ever whish to have a second person to work on a TYPO3 page together with you? This extension allows you to do so. With the help of ai you can now work on a page together with a cowriter - a digital assistant that helps you to write your content.

![TYPO3 AI cowriter](Documentation/Images/t3-cowriter.gif)
> Give it a try with our TYPPO3 mock and let the AI write for you: [Demo](https://t3ai.surge.sh/)

## Installation

Currently the extension is not available via TER.
Install the extension via composer:

```bash
composer require netresearch/t3-cowriter
```

## Configuration

1. Create a new API key at [https://openai.com/api/](https://openai.com/api/)
2. Add the API key to your TYPO3 Extension configuration (Extension Manager -> Settings -> Extension Configuration -> t3_cowriter)
3. Having no own RTE Configuration:
    * Add EXT:t3_cowriter to your root page Page TSconfig -
     Include static Page TSconfig (from extensions)
   ![PageTSCongfig](Documentation/Images/pagetsconfig.png)
4. With an own RTE Configuration yml file (your_ext/Configuration/RTE/YourConfig.yml):
    * add the Plugin.yml to your imports and the plugin js to externalPlugins:
```yml
imports:
  - { resource: "EXT:t3_cowriter/Configuration/RTE/Plugin.yaml" }

editor:
  externalPlugins:
    cowriter:
      resource: "EXT:t3_cowriter/Resources/Public/JavaScript/Plugins/cowriter/"

```
   

## Functionality

1. You'll get a new button in your editor for content elements.
2. Press the button and enter a description for the text you want to generate

## License

This project is licensed under the GNU GENERAL PUBLIC LICENSE - see the [LICENSE](LICENSE) file for details.

### Contact

[Netresearch](https://www.netresearch.de/), the company behind this plugin, is a leading European provider of digital solutions and services for the eCommerce industry. We are a team of eCommerce experts, developers, designers, project managers, and consultants. We are passionate about eCommerce and we love to share our knowledge with the community.

> [Twitter](https://twitter.com/netresearch) | [LinkedIn](https://www.linkedin.com/company/netresearch/) | [Facebook](https://www.facebook.com/netresearch/) | [Xing](https://www.xing.com/companies/netresearchdttgmbh) | [YouTube](https://www.youtube.com/@netresearch)
