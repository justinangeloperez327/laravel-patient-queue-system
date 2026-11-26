<?php

// Staff do not subscribe to broadcast channels yet. Display devices use
// private "display-device.{id}" channels, authorized by DisplayController
// against the device's paired session rather than a user (see phase 06).
