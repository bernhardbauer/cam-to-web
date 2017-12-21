# CAM to WEB

This library may be used to re-stream camera feeds to a web page. It has been written to support live streaming from Unifi UVC G3 and UVC G3 Dome cameras. As of writing this there has been no other easy and open implementation, which **does not require Flash or Java**, to get a RTSP stream displayed on a web page.



## Installation

1. Clone this project to a local work directory
2. Open a terminal and change into the directory of the downloaded project
3. Install composer using the [installation description provided by getcomposer.org](https://getcomposer.org/download/)
4. Run `composer install`
5. You may now start re-streaming videos. See the usage section for details about how to run the library



## Usage

 To start re-streaming use `ffmpeg -y -rtsp_transport tcp -i RTSP_URL -an -q:v 1 -b:v 1000k -vsync 0 -vf fps=fps=15 -hide_banner -f image2 -updatefirst 1 pipe:1 | php src/re-stream.php`. It retrieves the RTSP stream, sends it through a parsing script and creates a new WebSocket server on the given port which will be used to distribute the image data to the clients.



### Command Description

* `-rtsp_transport tcp` Use the TCP protocol to retrieve data from the RTSP url. If you are able to retrieve a stable stream over UDP this option may be omitted
* `-an` Disable Audio
* `-q:v 1` Specify the video quality. 1 = Highest Quality -> higher number = less image quality
* `-b:v 1000k` Output Bitrate (May be omitted)
* `-vsync 0` Only send new frames to the client (Do not send duplicate images)
* `-vf fps=fps=15` Set the frame rate to 15 frames per second
* `-hide_banner` Hides ffmpeg banner
* `-f image2` Specifies the format of the images which will be sent to the streaming script
