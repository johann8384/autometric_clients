package net.box.autometrics.processing

import com.yammer.metrics.core.MetricName
import com.yammer.metrics.reporting.GraphiteReporter
import com.yammer.metrics.Metrics
import java.util.concurrent.TimeUnit
import com.yammer.metrics.reporting.{JmxReporter, OpenTSDBReporter, ConsoleReporter}


class MetricCatcher {
  /*
  * These values should be in a configuration file
  */
  val DEFAULT_MEASUREMENT_UNIT = "MILLISECONDS"
  val DEFAULT_REPORTING_UNIT =  "SECONDS"
  val DEFAULT_REPORTING_INTERVAL = 1
  val CONSOLE_REPORTER_ENABLED = true
  val OPENTSDB_REPORTER_ENABLED = false
  val OPENTSDB_REPORTER_HOST = "opentsdb-slaves"
  val OPENTSDB_REPORTER_PORT = 4242
  val GRAPHITE_REPORTE_ENABLED = true
  val GRAPHITE_REPORTER_HOST = "graphite"
  val GRAPHITE_REPORTER_PORT = 2003
  val JMX_REPORTER_ENABLED = false

  type MetricHandler = (MetricName, Map[String, Any]) => String
  val metricHandlers  = Map("counter" -> handleCounter _, "timer" -> handleTimer _, "meter" -> handleMeter _, "biased" -> handleBiased _, "uniform" -> handleUniform _)

  /*
   * Initialize Reporters based on whether or not Config values are present
   */
  if (CONSOLE_REPORTER_ENABLED)
  {
    ConsoleReporter.enable(DEFAULT_REPORTING_INTERVAL, TimeUnit.valueOf(DEFAULT_REPORTING_UNIT))
  }
  if (OPENTSDB_REPORTER_ENABLED)
  {
    OpenTSDBReporter.enable(DEFAULT_REPORTING_INTERVAL,TimeUnit.valueOf(DEFAULT_REPORTING_UNIT), OPENTSDB_REPORTER_HOST, OPENTSDB_REPORTER_PORT)
  }

  if (JMX_REPORTER_ENABLED)
  {
    JmxReporter.startDefault(Metrics.defaultRegistry())
  }

  if (GRAPHITE_REPORTE_ENABLED)
  {
      GraphiteReporter.enable(1,TimeUnit.valueOf(DEFAULT_REPORTING_UNIT), GRAPHITE_REPORTER_HOST, GRAPHITE_REPORTER_PORT)
  }

  def handleMetric(metricMap : Map[String, Any]) : String = {
    //println(metricMap.toString())
    try {
      val metricType = metricMap("type").toString

      val name = metricMap("name").toString
      println("the name is " + name)

      val nameParts : Array[String] = name.split(".")
      println("there are " + nameParts.length.toString + " parts")

      val category = nameParts(0)
      nameParts.drop(1)
      println("category is " + category)

      val app = nameParts(0)
      nameParts.drop(1)
      println("app is " + app)

      val metricName: MetricName = new MetricName(category, app, nameParts.reduceLeft((_ + "." + _)))

      internalMetrics

      metricHandlers.get(metricType) match {
        case Some(handleFunction) => return handleFunction(metricName, metricMap)
        case None => throw new Exception("No Handler for " + metricType + " defined")
      }
    }
    catch {
      case e: Exception => {
        println(e.toString)
        return "FAILURE: " + e.toString
      }
    }
  }

  def internalMetrics()
  {
    val metricName = new MetricName("autometrics", "meter", "metrics.read")
    val metric = Metrics.newMeter(metricName, "requests", TimeUnit.SECONDS)
    metric.mark(1)
  }

  def handleCounter(metricName : MetricName, metricMap : Map[String, Any]) : String = {
    val metricValue = metricMap("value").toString.toLong
    val metric = Metrics.newCounter(metricName)
    if (metricMap.contains("reset") && metricMap("reset").toString.toBoolean)
    {
      metric.clear()
    }
    metric.inc(metricValue)
    return "SUCCESS: " + metricName + " is currently " + metric.count()
  }

  def handleMeter(metricName : MetricName, metricMap : Map[String, Any]) : String = {
    val metricValue = metricMap("value").toString.toLong
    var meterType = "requests"
    var meterUnit = TimeUnit.SECONDS

    if (metricMap.contains("event_type")) {
      meterType = metricMap("event_type").toString
    }

    if (metricMap.contains("unit")) {
      meterUnit = TimeUnit.valueOf(metricMap("unit").toString)
    }

    val metric = Metrics.newMeter(metricName, meterType, meterUnit)
    metric.mark(metricValue)
    return "SUCCESS: " + metricName + " is currently " + metric.oneMinuteRate() + ", " + metric.fiveMinuteRate() + ", " + metric.fifteenMinuteRate()
  }

  def handleTimer(metricName : MetricName, metricMap : Map[String, Any]) : String = {
    val metricValue = metricMap("value").toString.toLong
    var timerUnit = TimeUnit.MILLISECONDS
    if (metricMap.contains("unit"))
    {
           timerUnit = TimeUnit.valueOf(metricMap("unit").toString)
    }
    val metric = Metrics.newTimer(metricName, TimeUnit.valueOf(DEFAULT_MEASUREMENT_UNIT), TimeUnit.valueOf(DEFAULT_REPORTING_UNIT))
    metric.update(metricValue, timerUnit)
    return "SUCCESS: " + metricName + " is currently " + metric.oneMinuteRate() + ", " + metric.fiveMinuteRate() + ", " + metric.fifteenMinuteRate()
  }

  def handleBiased(metricName : MetricName, metricMap : Map[String, Any]) : String = {
    val metricValue = metricMap("value").toString.toLong
    val metric = Metrics.newHistogram(metricName, true)
    metric.update(metricValue)
    return "SUCCESS: " + metricName + " is currently : min(" + metric.min + "), max(" + metric.max + "), mean(" + metric.mean + ")"
  }

  def handleUniform(metricName : MetricName, metricMap : Map[String, Any]) : String = {
    val metricValue = metricMap("value").toString.toLong
    val metric = Metrics.newHistogram(metricName, false)
    metric.update(metricValue)
    return "SUCCESS: " + metricName + " is currently : min(" + metric.min + "), max(" + metric.max + "), mean(" + metric.mean + ")"
  }
}
