package net.box.autometrics.server

import org.jboss.netty.channel.{ ChannelHandlerContext, ExceptionEvent, MessageEvent, SimpleChannelUpstreamHandler }
import net.box.autometrics.processing.MetricCatcher
import com.codahale.jerkson.Json

class Handler extends SimpleChannelUpstreamHandler {

  override def messageReceived(ctx: ChannelHandlerContext, e: MessageEvent) {
    val msg = e.getMessage.toString
    val mc = new MetricCatcher
    //println(msg)
    val metricMapList =  Json.parse[List[Map[String, Any]]](msg)
    //println(metricMap.toString())
    metricMapList.foreach( f=> e.getChannel.write(mc.handleMetric(f), e.getRemoteAddress))
      //val metric = mc.handleMetric(metricMap)
      //e.getChannel.write(metric.toString, e.getRemoteAddress)
  }

  override def exceptionCaught(context: ChannelHandlerContext, e: ExceptionEvent) {
    e.getCause.printStackTrace()
    // We don't close the channel because we can keep serving requests.
  }
}