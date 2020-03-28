# Extension:TorqueDataConnect

The TorqueDataConnect extension connects to a running instance of torquedata
(see https://github.com/OpenTechStrategies/torque/tree/torquedata/torquedata)
to serve up information.

## Installation

* Download and place the file(s) in a directory called Comments in your extensions/ folder
* Add the following line to your LocalSettings.php
```
wfLoadExtension('TorqueDataConnect');
```

## Usage

## API

TorqueDataConnect provides one api endpoint 'torquedataconnect' that currently only
returns a list of proposals in a subobject of 'proposals'.  It takes a path that's not
currently used.

This needs to be built out to allow configuration of what api endpoints are allowed,
and how they translate to torquedata behind the scenes.

## Parameters

## Rights

## Internationalization

Currently only has support for English.
