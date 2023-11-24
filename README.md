# psa-connect-domo
PHP script to pull battery SoC data from PSA Car Connect API and push into Domoticz

API flow scraped from the excellent work done by Florian B and many project contributors here: https://github.com/flobz/psa_car_controller

I needed something smaller/simpler that just automates the battery SoC reading for my smart home controller (Domoticz) to regulate charging. PHP is my weapon of choice for stuff like this - I just like it I guess :) More importantly PHP5.6 can actually run on my ancient Synology NAS (unlike Python which makes for a crap ton of rebuilding std libraries that all fail somehow.) Also I can't run Docker on the NAS; it's too old and the wrong CPU :(

IMPORTANT: This script does NOT attempt to do the whole account login/OTP flow required by the API! That I currently do in Florian's tools on a capable PC and then scrape the refresh keys into this script along with the vehicle Id and othe quasi-static data. Good enough :)

Based on a similar tool previously written by me for the Nissan API to pull the Leaf battery SoC: https://github.com/slash909uk/nissan-connect-domo but I crashed taht car and now I have PSA/Stellantis to deal with...

Depends on a couple of other small PHP projects in my repos; phpMQTT and Syslog. You can find these in my public repo list.
