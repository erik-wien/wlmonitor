## monitor UI

### line number

square (5px rounded corners) or a circle 2 lines high and width 

White font if not stated otherwise.

ptTram = black circle. (Inverted in dark mode)

ptBusRegion = circle with yellow font.

ptMetro = square
	- U1 = red
	- U2 = purple
	- U3 = orange
	- U4 = green
	- U5 = dark cyan
	- U6 = brown

ptTrain = #e2001a square

ptTrainS = #0000ff blue square 

ptBusCity = #000080 square

ptBusNight = #000080 square with orange font

ptTramWLB 

> use /img/Logo_Wiener_Lokalbahn.svg in white in a blue square 


#### Example

<div style="width: 2em; height: 2em; background-color: #e2001a; display: flex; justify-content: center; align-items: center;color: white; font: bold 2em sans-serif;">U1</div>

<br>
<div style="width: 2em; height: 2em; background-color: #000080;  display: grid; place-items: center; color: white; font: bold 2em sans-serif;"> <img scr="../img/Logo_Wiener_Lokalbahn.svg"> </div>



### departure info

right next to the line number.

-  platform
-  the ending station 
- departure times 
- 1. Line for the outgoing train, in the second line there should be the incoming line.

Ask me if you don't know where to find that information about incoming and outgoing directions.

# footer

Ad a central version number. The current version number is 3.0. make the version number in the footer accordingly dynamic.

Ad a build number to the version number. `APP_BUILD` is an integer that is incremented on major updates only (not a date, not per session).

# selecting a favorite or a station

On selecting a favorite the monitor says "Keine Abfahren verfügbar". He doesn't find departures.

Same for selecting a station.

Might have to do with changing from rbl to diva.

#search

Hitting the "Nähe" button doesn't update the list.

# search

I'd like to make the search function use less browser estate. Can we put the search function in the header as  a dropdown field with attached buttons for a-z-search or geo-search? suggest a solution for the filter function 


# monitor_ json export

I miss monitor_json.php. This script returns a Jason object for HomeAssistant. 

You find the old version in deprecated/monitor_json.php

Restore it and adapt it to the new architecture.

Add a test for this service.