package net.box.autometrics.sender
import org.jboss.netty.channel.{ ChannelHandlerContext, ExceptionEvent, MessageEvent, SimpleChannelUpstreamHandler }

/**
 * Handles a client-side channel.
 */
class Handler extends SimpleChannelUpstreamHandler {

  override def messageReceived(ctx: ChannelHandlerContext, e: MessageEvent) {
    val msg = e.getMessage.toString
    System.out.println("Metric Status: " + msg)
    e.getChannel.close()
  }

  override def exceptionCaught(context: ChannelHandlerContext, e: ExceptionEvent) {
    e.getCause.printStackTrace()
    e.getChannel.close()
  }
}