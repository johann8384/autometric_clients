#!/usr/bin/python
""" Tail and LogTail based on classes here: https://github.com/ganglia/ganglia_contrib/blob/master/ganglia-logtailer/src/tailnostate.py """

import time, os, sys, glob, json, signal, re, iso8601, datetime, pytz, random

import logging

#FORMATS for logger
LOGGER_FMT          = "%(asctime)s %(levelname)s:::%(name)s:::%(message)s"
LOGGER_DATE_FMT     = "%m/%d/%Y %H:%M:%S"
DEBUG               = False

metrics_read = 0
metrics_sent = 0
metrics_discarded = 0
lines_read = 0

metric_path = '/var/log/application/json_metrics'
metric_file = '/var/log/application/json_metrics/json_metrics.log'
position_path = '/var/run'
position_file = '/var/run/autometrics.position'


def printf(format, *args):
    sys.stdout.write(format % args)

def errprintf(format, *args):
    sys.stderr.write(format % args)

def signal_handler(signal, frame):
    sys.exit(0)

signal.signal(signal.SIGINT, signal_handler)

class Tail(object):
    def __init__(self, filename, start_pos=0):
        self.fp = file(filename)
        self.filename = filename

        if start_pos < 0:
            self.fp.seek(-start_pos-1, 2)
            self.pos = self.fp.tell()
        elif start_pos == 0 and os.path.isfile(position_file):
            try:
                f = open(position_file)
                start_pos = pickle.load(f)
                f.close()
                self.fp.seek(start_pos)
                self.pos = start_pos
            except Exception:
                self.fp.seek(start_pos, 2)
                self.pos = start_pos
                pass
        else:
            self.fp.seek(start_pos)
            self.pos = start_pos

    def __iter__(self):
        """Return next line.  This function will sleep until there *is* a
        next line.  Works over log rotation."""
        counter = 0
        while True:
            line = self.next()
            if line is None:
                counter += 1
                if counter >= 5:
                    counter = 0
                    self.check_inode()
                time.sleep(1.0)
            else:
                yield line

    def check_inode(self):
        """check to see if the filename we expect to tail has the same
        inode as our currently open file.  This catches log rotation"""
        inode = os.stat(self.filename).st_ino
        old_inode = os.fstat(self.fp.fileno()).st_ino
        if inode != old_inode:
            self.fp = file(self.filename)
            self.pos = 0

    def next(self):
        """Return the next line from the file.  Returns None if there are not
        currently any lines available, at which point you should sleep before
        calling again.  Does *not* handle log rotation.  If you use next(), you
        must also use check_inode to handle log rotation"""
        where = self.fp.tell()
        line = self.fp.readline()
        if line and line[-1] == '\n':
            self.pos += len(line)
            try:
                if os.path.isdir(position_path):
                    f = open(position_file, 'w')
                    pickle.dump(self.pos, f)
                    f.close()
            except Exception:
                pass
            return line
        else:
            self.fp.seek(where)
            return None

    def close(self):
        self.fp.close()



class LogTail(Tail):
    def __init__(self, filename):
        self.base_filename = filename
        super(LogTail, self).__init__(filename, -1)

    def get_file(self, inode, next=False):
        files = glob.glob('%s*' % self.base_filename)
        files = [(os.stat(f).st_mtime, f) for f in files]
        # Sort by modification time
        files.sort()

        flag = False
        for mtime, f in files:
            if flag:
                return f
            if os.stat(f).st_ino == inode:
                if next:
                    flag = True
                else:
                    return f
        else:
            return self.base_filename

    def reset(self):
        self.fp = file(self.filename)
        self.pos = 0
        try:
            if os.path.isdir(position_path):
                f = open(position_file, 'w')
                pickle.dump(self.pos, f)
                f.close()
        except Exception:
            pass

    def advance(self):
        self.filename = self.get_file(os.fstat(self.fp.fileno()).st_ino, True)
        self.reset()

    def check_inode(self):
        if self.filename != self.base_filename or os.stat(self.filename).st_ino != os.fstat(self.fp.fileno()).st_ino:
            self.advance()

def handle_metric(line):
            global lines_read
            global metrics_read
            global metrics_sent
            global metrics_discarded
            metric_name = 'unidentified'
            metric_timestamp = str(int(time.time()))
            metric_value = 0
            metric_type = 'counter'
            metric_tags = ' env=unknown'
            meter_event = 'events'
            meter_unit = 'SECONDS'
            logging.debug("Input line: %s\n", line)
            lines_read += 1

            jdata = json.loads(line)

            metrics_read += 1

            for key, value in jdata.iteritems():
                if value != None and key != None:
                    logging.debug(" key:%s => value:%s", key, value);
                    if key == 'name' or key == 'metric_name':
                        metric_name=value
                    elif key == 'type' or key == 'metric_type':
                        metric_type=value
                    elif key == 'tags' or key == 'metric_tags':
                        metric_tags = ' category=metrics'
                        for tagk, tagv in value.items():
                            if tagv != None and tagk != None and tagk not in ('server_type','dc', 'host'):
                                metric_tags += ' ' + tagk + '=' + cleanUpTagValue(tagv)

                    elif key == 'timestamp':
                        metric_timestamp=int(value)
                    elif key == 'value' or key == 'metric_value':
                        metric_value=value

            if metric_type == 'counter':
                metrics_sent += 1
                printf('autometrics.counters.%s %s %s %s\n', metric_name, metric_timestamp, metric_value, metric_tags)
            elif metric_type == 'meter':
                metrics_sent += 1
                printf('autometrics.meters.%s %s %s unit=%s event_type=%s%s\n', metric_name, metric_timestamp, metric_value, meter_unit, meter_event, metric_tags)
            elif metric_type == 'timer':
                metrics_sent += 1
                printf('autometrics.timers.%s %s %s %s\n', metric_name, metric_timestamp, metric_value, metric_tags)
            else:
                metrics_discarded += 1

def cleanUpTagValue(tagv):
    tagv = str(tagv)
    tagv = tagv.replace(':', '_')
    tagv = tagv.replace(' ', '_')
    tagv = tagv.replace('<', '')
    tagv = tagv.replace('>', '')
    tagv = tagv.replace('\'', '')
    return tagv

def return_true_percentage_of_time(percentage):
    return random.random() < percentage

def write_delay(timestamp):
    try:
      logtime = iso8601.parse_date(timestamp[0:24])
      currenttime = datetime.datetime.utcnow().replace(tzinfo=pytz.utc)
      td = (currenttime-logtime)
      # Measure how far behind the timestamp of the recently read line is from Now()
      diff = (td.microseconds + (td.seconds + td.days * 24 * 3600) * 10**6) / 10**6

      # Timers are ms so multiply by 1000
      printf('autometrics.timers.reader.delay %s %s\n', str(int(time.time())), diff * 1000)
    except Exception as e:
      errprintf('An error occurred while recording reader delay metrics: %s \n', str(e))

def send_metrics():
      global metrics_sent
      global metrics_read
      global metrics_discarded
      global lines_read

      printf('autometrics.counters.lines.read %s %s\n', str(int(time.time())), lines_read)
      printf('autometrics.counters.metrics.read %s %s\n', str(int(time.time())), metrics_read)
      printf('autometrics.counters.metrics.sent %s %s\n', str(int(time.time())), metrics_sent)
      printf('autometrics.counters.metrics.discarded %s %s\n', str(int(time.time())), metrics_discarded)

      metrics_read = 0
      metrics_sent = 0
      metrics_discarded = 0
      lines_read = 0

def main():
    try:
        #set logger based on the DEBUG flag
        if DEBUG:
            logging.basicConfig(level=logging.DEBUG, format=LOGGER_FMT, datefmt=LOGGER_DATE_FMT)
            logging.debug("Enabled Debug Logging");
        else:
            logging.basicConfig(level=logging.INFO, format=LOGGER_FMT, datefmt=LOGGER_DATE_FMT)

        import sys
        if os.path.isdir(metric_path) and os.path.isfile(metric_file):
            t = Tail(metric_file, -1)
            for line in t:
                if return_true_percentage_of_time(0.1):
                    write_delay(line)
                    send_metrics()
                json_regex = re.compile("\{.*")
                match = json_regex.search(line)
                json = match.group(0)
                handle_metric(json)
        else:
          exit()
    except Exception as e:
        errprintf('An error occurred in processing: %s \n', str(e))

if __name__ == '__main__':
    main()
