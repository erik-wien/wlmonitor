Title: Wiener Abfahrtsmonitor
Subtitel:	Dokumentation
Author:	Erik R. Huemer 
Date:	24.04.2019
Web:	https://www.jardyx.com/wl-monitor
Tags:	Wiener Linien, Public transport, real time data
Language:	german  
Version:	1.4  
Copyright:	© 2019 Erik R. Huemer  
CSS:	https://www.jardyx.com/markdown/css/markdown.css  
CSS:	https://www.jardyx.com/markdown/css/Numbered_Headers.css
Format: 	snippet


# Wiener Abfahrtsmonitor

````
https://www.jardyx.com/wl-monitor/
````

Autor
: Erik R. Huemer

Kontakt
:	<info@2me.org>

URL
: [https://www.jardyx.com/wl-Monitor](https://www.jardyx.com/wl-Monitor)

![Screenshot][]

## Verwendete Technologien

- HTML, Javascript, PHP, Bootstrap, jQuery.

## Abhängigkeiten

- Font Awesome
- Google Fonts (Roboto)
- Geodesy (js)
- API Wiener Linien
- hactar Stationsverzeichnis 

## Versionsverlauf 

- V 1.0 Erstansatz: April 2019
- V 1.1 Geo-Buttons, Suche im vollständigen Stationsverzeichnis 
- V 1.2 Cookies
- V 1.3 Navigation, Dokumentation
- V.1.4 User- und Favoritenverwaltung
- V.1.4.1 Angemeldet bleiben, Username für Login merken
- geplant: 
	- Kennwort ändern
	- Farben der Favoriten einstellen
	- eigenes Icon
	- Darkmode Erkennung
	- Favoriten sortieren

## Struktur

![Struktur][]
 
## Elemente

-	JSON Dekodierung der Realtime-Daten der Wiener Linien API
-	PHP zur Erzeugung des HTML Codes des Monitors
-	PHP + mySQL für die Userverwaltung und die Favoriten
-	Bootstrap CSS zur responsive Formatierung
-	JQuery mit Ajax
-	Geodesy zur Verarbeitung der Geodaten
-	Cookie Verwaltung (persistiert die aktuelle Haltestellen-Auswahl)


## Funktion

Die Seite besteht aus einer Liste der Abfahrtszeiten an einem gewählten Ort und einer Liste von Buttons, mit denen die Anzeige des Monitors gesteuert werden kann.
Die Liste der Buttons ist fix gespeichert (hart codiert); sofern man sich registriert, kann man die Buttons in Form einer Favoriten-Verwaltung selbst konfigurieren.

Beim ersten Aufruf werden die Abfahrtszeiten der U1 am Stephansplatz gezeigt. Die zuletzt angezeigte Station wird in einem Cookie für drei Tage gespeichert.

Die Abfahrtszeiten selbst werden von einer Api der Wiener Linien über die sogenannte RBL-Nummer abgefragt.

Diese RBL-Nummer wird an ein PHP Script übergeben, welches die Daten in Form eines JSON-Pakets von der API der Wr. Verkehrsbetriebe bezieht. Dabei können auch mehrere RBL-Nummern übergeben werden, um auch nahe gelegene Stationen mitzuberücksichtigen. Der Stephansplatz hat zum Beispiel vier solche RBL-Nummern.

Zusätzlich kann die Position des Users ermittelt werden; dann wird die Liste aller Stationen im Suchfeld nach Entfernung vom User sortiert..

## Cookies & Datenschutz

Die Webapp verwendet Cookies nur, um Ihre zuletzt gewählte Station zu speichern. Cookies werden auf Ihrem Rechner gespeichert, wir bekommen sie niemals zu Gesicht.

Cookies bleiben drei Tage gespeichert und werden dann automatisch gelöscht. Ihr ok, dass wir Cookies verwenden,  bleibt 365 Tage in Ihrem Browser gespeichert.

Sofern Sie sich als Benutzer des Systems registrieren, bekommen Sie die Möglichkeit Favoriten anzulegen. Wir speichern dafür den von Ihnen bekannt gegebenen Namen, die Mail-Adresse und einen Passwort-hash. Das heisst wir können feststellen, ob Ihr Passwort richtig ist, kennen das Passwort selbst aber nicht.

Registrierung, Logins, Logouts und Vorgänge bei der Favoriten-Verwaltung werden in einem "Logbuch" gespeichert, das Sie sich in Ihrem Profil jederzeit ansehen können. Die Eintragungen des Logbuchs planen wir nicht länger als 360 Tage aufzubewahren und ca. einmal im Jahr zu löschen. Ihre Registrierungsdaten heben wir maximal 7 Jahre nach Beendigung Ihrer Registrierung oder nachdem wir dieses Service eingestellt zu Dokumentationszwecken auf.

Ihrer Daten werden zu keinen anderen Zweck als zur Bereitstellung dieses Services verarbeitet und werden an keine Dritten weitergegeben.

## Mitgeltende Dokumente

- Wiener Linien Realtime | [Schnittstellendokumentation][]
- Wiener Linien [Gesamtnetzplan](https://www.wienerlinien.at/media/files/2016/gesamtnetzplan_wien_176236.pdf)
- [Dieses Dokument](readme.md) im Format markdown 

## Dank

Danke an folgende Personen und Institutionen, dass sie Ihr wissen und Ire Daten zu Verfügung gestellt haben:

[Matthias Bendel](https://mabe.at) 
: [WL-Monitor-Pi][], [WL RBL Search][]

[w3schools.com](https://w3schools.com)
: For the immensly helpful documentation and examples.

Patrick Wolowicz [hactar][]
: Für die Aufbereitung der [Stationsdaten][], was die Abfrage von nahegelegenen Stationen erst möglich gemacht hat.

Wiener Linien
: Danke für das Bereitstellen ihrer Echtzeitdaten! Es lebe Open Data! ;-)

[Ruadhán O'Donoghue](https://mobiforge.com/design-development/geo-sorting-using-device-geolocation-to-sort-distance)
: For that really helpful  article about sorting a list of POIs regarding the own position and

[Chris Veness](https://www.movable-type.co.uk/scripts/latlong-nomodule.html) of Movable Type
: For his fundamentally helpful work on distance calculation and the script geodesy he generously offers online.

[Screenshot]: img/doku/screenshot.png "Screenshot" class="img-fluid"  style="max-width: 100%"
[Struktur]: img/doku/struktur.svg "Dokumentstruktur" class="img-fluid"  style="max-width: 100%"
[Schnittstellendokumentation]: https://data.wien.gv.at/pdf/wienerlinien-echtzeitdaten-dokumentation.pdf 
[WL-Monitor-Pi]: https://derstandard.at/2000034622153/Wiener-Linien-Abfahrtsmonitor-mit-Raspberry-Pi-gebastelt
[WL RBL Search]: https://till.mabe.at/rbl/
[hactar]: https://github.com/hactar
[Stationsdaten]: https://gist.github.com/hactar/6793144
