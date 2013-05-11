package net.box.autometrics.server

import org.jboss.netty.channel.socket.nio.NioDatagramChannelFactory
import java.util.concurrent.Executors
import org.jboss.netty.bootstrap.ConnectionlessBootstrap
import org.jboss.netty.channel.{FixedReceiveBufferSizePredictorFactory, Channels, ChannelPipelineFactory}
import org.jboss.netty.handler.codec.string.{StringDecoder, StringEncoder}
import org.jboss.netty.util.CharsetUtil
import java.net.InetSocketAddress

object Receiver {

  def main(args: Array[String]) {
    val f = new NioDatagramChannelFactory(Executors.newCachedThreadPool)

    val b = new ConnectionlessBootstrap(f)

    // Configure the pipeline factory.
    b.setPipelineFactory(new ChannelPipelineFactory {
      override def getPipeline = Channels.pipeline(
        new StringEncoder(CharsetUtil.ISO_8859_1),
        new StringDecoder(CharsetUtil.ISO_8859_1),
        new Handler)

    })
    // Server doesn't need to enable broadcast to listen to a broadcast.
    b.setOption("broadcast", "false")

    // Allow packets as large as up to 1024 bytes (default is 768).
    // You could increase or decrease this value to avoid truncated packets
    // or to improve memory footprint respectively.
    //
    // Please also note that a large UDP packet might be truncated or
    // dropped by your router no matter how you configured this option.
    // In UDP, a packet is truncated or dropped if it is larger than a
    // certain size, depending on router configuration.  IPv4 routers
    // truncate and IPv6 routers drop a large packet.  That's why it is
    // safe to send small packets in UDP.
    b.setOption(
      "receiveBufferSizePredictorFactory",
      new FixedReceiveBufferSizePredictorFactory(1024))

    // Bind to the port and start the service.
    b.bind(new InetSocketAddress(8080))
  }
}
