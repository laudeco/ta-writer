#!/bin/sh
# Starts a virtual display and noVNC so the AGIR login window can be seen in a browser on the host.
# Bound to 127.0.0.1 only: the session carries a logged-in Confluence/Jira cookie.
set -e
export DISPLAY=:99
Xvfb :99 -screen 0 1440x900x24 >/tmp/xvfb.log 2>&1 &
x11vnc -display :99 -forever -nopw -localhost -rfbport 5900 >/tmp/x11vnc.log 2>&1 &
websockify --web /usr/share/novnc 127.0.0.1:6080 127.0.0.1:5900 >/tmp/novnc.log 2>&1 &
echo "noVNC on http://127.0.0.1:6080/vnc.html"
